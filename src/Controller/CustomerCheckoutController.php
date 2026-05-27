<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class CustomerCheckoutController extends AbstractController
{
    #[Route('/customer/checkout', name: 'app_customer_checkout', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function checkout(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('customer_checkout', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_customer_cart');
        }

        $linesRaw = $request->request->get('lines');
        if (!\is_string($linesRaw) || $linesRaw === '') {
            $this->addFlash('error', 'Your cart is empty.');

            return $this->redirectToRoute('app_customer_cart');
        }

        try {
            /** @var list<array{productId?: int, quantity?: int}>|null $lines */
            $lines = json_decode($linesRaw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->addFlash('error', 'Could not read your cart. Please refresh and try again.');

            return $this->redirectToRoute('app_customer_cart');
        }

        if (!\is_array($lines) || $lines === []) {
            $this->addFlash('error', 'Your cart is empty.');

            return $this->redirectToRoute('app_customer_cart');
        }

        $order = new Order();
        $order->setCustomerName($user->getName() ?? $user->getUserIdentifier());
        $order->setStatus('Pending');
        $order->setCreatedAt(new \DateTimeImmutable());
        $order->setCreatedBy($user);
        $order->setPlacedBy($user);

        $total = 0.0;
        $em->beginTransaction();

        try {
            foreach ($lines as $line) {
                $productId = isset($line['productId']) ? (int) $line['productId'] : 0;
                $qty = isset($line['quantity']) ? (int) $line['quantity'] : 0;
                if ($productId < 1 || $qty < 1) {
                    continue;
                }

                $product = $em->find(Product::class, $productId);
                if (!$product instanceof Product) {
                    continue;
                }

                $stock = $product->getQuantity();
                if ($stock < $qty) {
                    $em->rollback();
                    $this->addFlash('error', sprintf('Not enough stock for "%s". Only %d available.', $product->getName(), $stock));

                    return $this->redirectToRoute('app_customer_cart');
                }

                $unit = $product->getPrice();
                $item = new OrderItem();
                $item->setProduct($product);
                $item->setQuantity($qty);
                $item->setUnitPrice($unit);
                $order->addItem($item);

                $product->setQuantity($stock - $qty);

                $total += $unit * $qty;
            }

            if ($order->getItems()->isEmpty()) {
                $em->rollback();
                $this->addFlash('error', 'No valid items in your cart.');

                return $this->redirectToRoute('app_customer_cart');
            }

            $order->setTotalPrice($total);

            $em->persist($order);
            $em->flush();
            $em->commit();

            // Broadcast to WebSocket server so mobile apps update instantly
            try {
                $wsUrl = $_ENV['WEBSOCKET_SERVER_URL'] ?? 'http://127.0.0.1:8085/broadcast';
                $ch = curl_init($wsUrl);
                $payload = json_encode([
                    'event' => 'new-order',
                    'data' => [
                        'orderId' => $order->getId(),
                        'message' => "Order #{$order->getId()} was just placed."
                    ]
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                curl_exec($ch);
                curl_close($ch);
            } catch (\Exception $e) {
                // Ignore socket errors
            }
        } catch (\Throwable $e) {
            $em->rollback();
            throw $e;
        }

        $this->addFlash('success', sprintf('Order #%d placed successfully! You can track it in My account.', $order->getId()));

        return $this->redirectToRoute('app_profile', ['checkout' => 'success']);
    }
}
