<?php

namespace App\Controller\Api;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\User;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/customer/orders')]
#[IsGranted('ROLE_USER')]
final class CustomerOrderApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/checkout', name: 'api_customer_checkout', methods: ['POST'])]
    public function checkout(Request $request, ProductRepository $products): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || empty($data['items'])) {
            return $this->json(['error' => 'Cart is empty'], Response::HTTP_BAD_REQUEST);
        }

        $order = new Order();
        $order->setCustomerName($user->getName() ?? $user->getEmail() ?? 'Customer');
        $order->setStatus('Pending');
        $order->setCreatedAt(new \DateTimeImmutable());
        $order->setPlacedBy($user);
        $order->setCreatedBy($user);

        $totalPrice = 0.0;

        foreach ($data['items'] as $cartItem) {
            $productId = (int) ($cartItem['productId'] ?? 0);
            $qty = (int) ($cartItem['qty'] ?? 0);

            if ($productId <= 0 || $qty <= 0) {
                continue;
            }

            $product = $products->find($productId);
            if (!$product) {
                return $this->json(['error' => "Product ID {$productId} not found"], Response::HTTP_NOT_FOUND);
            }

            if (($product->getQuantity() ?? 0) < $qty) {
                return $this->json(['error' => "Not enough stock for {$product->getName()}"], Response::HTTP_BAD_REQUEST);
            }

            // Deduct stock
            $product->setQuantity($product->getQuantity() - $qty);

            $unitPrice = $product->getPrice() ?? 0.0;
            $lineTotal = $unitPrice * $qty;

            $item = new OrderItem();
            $item->setProduct($product);
            $item->setQuantity($qty);
            $item->setUnitPrice($unitPrice);

            $order->addItem($item);
            $order->addProduct($product);
            $totalPrice += $lineTotal;
        }

        if ($order->getItems()->isEmpty()) {
            return $this->json(['error' => 'No valid items in cart'], Response::HTTP_BAD_REQUEST);
        }

        $order->setTotalPrice($totalPrice);

        $this->em->persist($order);
        $this->em->flush();

        // Broadcast to WebSocket server
        try {
            $wsUrl = $_ENV['WEBSOCKET_SERVER_URL'] ?? 'http://127.0.0.1:8085/broadcast';
            $ch = curl_init($wsUrl);
            $payload = json_encode([
                'event' => 'new-order',
                'data' => [
                    'orderId' => $order->getId(),
                    'customerName' => $order->getCustomerName(),
                    'totalPrice' => $order->getTotalPrice(),
                    'message' => "New order #{$order->getId()} placed by {$order->getCustomerName()}!"
                ]
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Exception $e) {
            // Silence socket/network errors
        }

        return $this->json($this->serializeOrder($order, true), Response::HTTP_CREATED);
    }

    #[Route('', name: 'api_customer_orders_history', methods: ['GET'])]
    public function history(OrderRepository $repo): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $orders = $repo->findBy(['placedBy' => $user], ['createdAt' => 'DESC']);

        return $this->json([
            'items' => array_map(fn (Order $o) => $this->serializeOrder($o, true), $orders),
        ]);
    }

    private function serializeOrder(Order $o, bool $detailed = false): array
    {
        $items = [];
        foreach ($o->getItems() as $item) {
            $prod = $item->getProduct();
            $items[] = [
                'id' => $item->getId(),
                'productId' => $prod?->getId(),
                'productName' => $prod?->getName(),
                'quantity' => $item->getQuantity(),
                'unitPrice' => $item->getUnitPrice(),
                'lineTotal' => $item->getLineTotal(),
                'image' => $prod?->getImage(),
            ];
        }

        $data = [
            'id' => $o->getId(),
            'totalPrice' => $o->getTotalPrice(),
            'status' => $o->getStatus(),
            'createdAt' => $o->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'itemCount' => count($items),
        ];

        if ($detailed) {
            $data['items'] = $items;
        }

        return $data;
    }
}
