<?php

namespace App\Controller;

use App\Entity\Product;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Form\StockMovementType;
use App\Repository\ProductRepository;
use App\Repository\StockMovementRepository;
use App\Service\LogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/stock')]
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF")'))]
final class StockController extends AbstractController
{
    #[Route(name: 'app_stock_index', methods: ['GET'])]
    public function index(
        Request $request,
        StockMovementRepository $stockMovementRepository,
        ProductRepository $productRepository,
    ): Response {
        $userFilter = $request->query->get('user');
        $productFilter = $request->query->get('product');
        $fromInput = $request->query->get('from');
        $toInput = $request->query->get('to');

        $from = $fromInput ? (new \DateTimeImmutable($fromInput))->setTime(0, 0, 0) : null;
        $to = $toInput ? (new \DateTimeImmutable($toInput))->setTime(23, 59, 59) : null;
        $productId = $productFilter !== null && $productFilter !== '' ? (int) $productFilter : null;

        return $this->render('stock/index.html.twig', [
            'movements' => $stockMovementRepository->findFiltered(
                is_string($userFilter) ? $userFilter : null,
                $productId,
                $from,
                $to,
            ),
            'products' => $productRepository->findBy([], ['name' => 'ASC']),
            'filters' => [
                'user' => is_string($userFilter) ? $userFilter : '',
                'product' => $productFilter !== null && $productFilter !== '' ? (string) $productFilter : '',
                'from' => is_string($fromInput) ? $fromInput : '',
                'to' => is_string($toInput) ? $toInput : '',
            ],
        ]);
    }

    #[Route('/new', name: 'app_stock_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        LogService $logService,
    ): Response {
        $movement = new StockMovement();
        $form = $this->createForm(StockMovementType::class, $movement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            return $this->persistMovement($movement, $em, $logService);
        }

        return $this->render('stock/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_stock_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(): Response
    {
        throw $this->createAccessDeniedException('Stock movements are read-only.');
    }

    #[Route('/{id}', name: 'app_stock_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(StockMovement $movement): Response
    {
        return $this->render('stock/show.html.twig', [
            'movement' => $movement,
        ]);
    }

    #[Route('/{id}', name: 'app_stock_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(): Response
    {
        throw $this->createAccessDeniedException('Stock movements are read-only.');
    }

    private function persistMovement(
        StockMovement $movement,
        EntityManagerInterface $em,
        LogService $logService,
    ): Response {
        $selected = $movement->getProduct();
        $amount = $movement->getAmount();

        if ($amount < 1 || !$selected?->getId()) {
            $this->addFlash('error', 'Please select a product and enter a valid quantity.');

            return $this->redirectToRoute('app_stock_new');
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        try {
            $em->wrapInTransaction(function (EntityManagerInterface $em) use ($movement, $user, $amount, $selected): void {
                $product = $em->find(Product::class, $selected->getId());
                if (!$product instanceof Product) {
                    throw new \RuntimeException('Product not found.');
                }

                $current = $product->getQuantity();
                $current = $current === null ? 0 : $current;
                $product->setQuantity($current + $amount);

                $movement->setProduct($product);
                $movement->setCreatedBy($user);
                $em->persist($movement);
            });
        } catch (\RuntimeException) {
            $this->addFlash('error', 'Could not update product stock. The product may have been removed.');

            return $this->redirectToRoute('app_stock_new');
        }

        $product = $movement->getProduct();
        $roleLabel = $this->isGranted('ROLE_ADMIN') ? 'Admin' : 'Staff';
        $actorName = $user->getName() ?? $user->getUserIdentifier();
        $logService->log(
            'STOCK_ADD',
            'Product',
            "{$roleLabel} {$actorName} added {$amount} stock to {$product->getName()} (new total: {$product->getQuantity()})",
        );

        $this->addFlash('success', "Added {$amount} to {$product->getName()}. New quantity: {$product->getQuantity()}.");

        // Broadcast to WebSocket server
        try {
            $wsUrl = $_ENV['WEBSOCKET_SERVER_URL'] ?? 'http://127.0.0.1:8085/broadcast';
            $ch = curl_init($wsUrl);
            $payload = json_encode([
                'event' => 'product-updated',
                'data' => [
                    'productId' => $product->getId(),
                    'name' => $product->getName(),
                    'action' => 'stock_update',
                    'newQuantity' => $product->getQuantity(),
                    'message' => "Stock added to {$product->getName()}!"
                ]
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Exception $e) { }

        return $this->redirectToRoute('app_stock_index');
    }
}
