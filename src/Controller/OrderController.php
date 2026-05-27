<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\User;
use App\Form\OrderType;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use App\Service\LogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/order')]
final class OrderController extends AbstractController
{
    private const ALLOWED_STATUSES = ['Pending', 'Processing', 'Shipped', 'Delivered', 'Completed', 'Cancelled'];

    #[Route(name: 'app_order_index', methods: ['GET'])]
    public function index(OrderRepository $orderRepository): Response
    {
        return $this->render('order/index.html.twig', [
            'orders' => $orderRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_order_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, UserRepository $userRepository, LogService $logService): Response
    {
        $order = new Order();
        $form = $this->createForm(OrderType::class, $order);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $selectedCustomer = $form->get('customer')->getData();
            if ($selectedCustomer instanceof User) {
                $order->setCustomerName($selectedCustomer->getName());
                $order->setPlacedBy($selectedCustomer);
            }

            $order->setCreatedBy($this->getUser());
            $entityManager->persist($order);
            $entityManager->flush();

            // LOG THE ACTION
            $logService->log('CREATE', 'Order', "Created order #{$order->getId()} for {$order->getCustomerName()}");

            $this->addFlash('success', 'Order has been created successfully!');
            return $this->redirectToRoute('app_order_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('order/new.html.twig', [
            'order' => $order,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_order_show', methods: ['GET'])]
    public function show(Order $order): Response
    {
        return $this->render('order/show.html.twig', [
            'order' => $order,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_order_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Order $order, EntityManagerInterface $entityManager, UserRepository $userRepository, LogService $logService): Response
    {
        // Keep legacy "products" field in sync with line items so ordered parts are preselected in edit form.
        if ($order->getProducts()->isEmpty() && !$order->getItems()->isEmpty()) {
            foreach ($order->getItems() as $item) {
                if ($item->getProduct()) {
                    $order->addProduct($item->getProduct());
                }
            }
        }

        $form = $this->createForm(OrderType::class, $order);
        
        if ($order->getCustomerName()) {
            $customer = $userRepository->findOneBy(['name' => $order->getCustomerName()]);
            if ($customer) {
                $form->get('customer')->setData($customer);
            }
        }
        
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $selectedCustomer = $form->get('customer')->getData();
            if ($selectedCustomer instanceof User) {
                $order->setCustomerName($selectedCustomer->getName());
                $order->setPlacedBy($selectedCustomer);
            }
            // Keep existing customer name if no new customer is selected
            elseif (!$order->getCustomerName()) {
                // If no customer name exists, set a default
                $order->setCustomerName('Guest Customer');
            }
            
            $entityManager->flush();

            // LOG THE ACTION
            $logService->log('UPDATE', 'Order', "Updated order #{$order->getId()}");
            
            // Broadcast to WebSocket server so mobile apps update in real-time
            try {
                $wsUrl = $_ENV['WEBSOCKET_SERVER_URL'] ?? 'http://127.0.0.1:8085/broadcast';
                $ch = curl_init($wsUrl);
                $payload = json_encode([
                    'event' => 'order-status-updated',
                    'data' => [
                        'orderId' => $order->getId(),
                        'customerId' => $order->getPlacedBy()?->getId(),
                        'status' => $order->getStatus(),
                        'message' => "Order #{$order->getId()} has been updated."
                    ]
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                curl_exec($ch);
                curl_close($ch);
            } catch (\Exception $e) {
                // Silence network errors
            }

            $this->addFlash('success', 'Order #' . $order->getId() . ' has been updated successfully!');
            return $this->redirectToRoute('app_order_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('order/edit.html.twig', [
            'order' => $order,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/status', name: 'app_order_update_status', methods: ['POST'])]
    public function updateStatus(Request $request, Order $order, EntityManagerInterface $entityManager, LogService $logService): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_STAFF')) {
            throw $this->createAccessDeniedException('You are not allowed to update order status.');
        }

        if (!$this->isCsrfTokenValid('update_status_'.$order->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Invalid request token.');
            return $this->redirectToRoute('app_order_show', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
        }

        $newStatus = trim($request->getPayload()->getString('status'));
        if (!in_array($newStatus, self::ALLOWED_STATUSES, true)) {
            $this->addFlash('error', 'Invalid status selected.');
            return $this->redirectToRoute('app_order_show', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
        }

        $oldStatus = $order->getStatus();
        if ($oldStatus === $newStatus) {
            return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('app_order_show', ['id' => $order->getId()]));
        }

        $order->setStatus($newStatus);
        $entityManager->flush();

        $logService->log('UPDATE', 'Order', "Updated order #{$order->getId()} status from {$oldStatus} to {$newStatus}");
        
        // Broadcast to WebSocket server so mobile apps update in real-time
        try {
            $wsUrl = $_ENV['WEBSOCKET_SERVER_URL'] ?? 'http://127.0.0.1:8085/broadcast';
            $ch = curl_init($wsUrl);
            $payload = json_encode([
                'event' => 'order-status-updated',
                'data' => [
                    'orderId' => $order->getId(),
                    'customerId' => $order->getPlacedBy()?->getId(),
                    'status' => $newStatus,
                    'message' => "Order #{$order->getId()} status updated to {$newStatus}!"
                ]
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Exception $e) {
            // Silence network errors
        }

        $this->addFlash('success', "Order #{$order->getId()} status updated to {$newStatus}.");

        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('app_order_show', ['id' => $order->getId()]));
    }

    #[Route('/{id}', name: 'app_order_delete', methods: ['POST'])]
    public function delete(Request $request, Order $order, EntityManagerInterface $entityManager, LogService $logService): Response
    {
        if ($this->isCsrfTokenValid('delete'.$order->getId(), $request->getPayload()->getString('_token'))) {
            $orderId = $order->getId();
            $entityManager->remove($order);
            $entityManager->flush();

            // LOG THE ACTION
            $logService->log('DELETE', 'Order', "Deleted order #{$orderId}");

            $this->addFlash('success', 'Order #' . $orderId . ' has been deleted successfully!');
        }

        return $this->redirectToRoute('app_order_index', [], Response::HTTP_SEE_OTHER);
    }
}