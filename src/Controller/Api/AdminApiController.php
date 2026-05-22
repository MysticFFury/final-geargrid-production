<?php

namespace App\Controller\Api;

use App\Entity\Category;
use App\Entity\Log;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Repository\CategoryRepository;
use App\Repository\LogRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Repository\StockMovementRepository;
use App\Repository\UserRepository;
use App\Service\LogService;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin')]
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF")'))]
final class AdminApiController extends AbstractController
{
    private const ORDER_STATUSES = ['Pending', 'Processing', 'Shipped', 'Delivered', 'Completed', 'Cancelled'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LogService $logService,
    ) {
    }

    #[Route('/dashboard', name: 'api_admin_dashboard', methods: ['GET'])]
    public function dashboard(
        ProductRepository $products,
        CategoryRepository $categories,
        OrderRepository $orders,
        UserRepository $users,
        LogRepository $logs,
    ): JsonResponse {
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $recentOrders = $orders->findBy([], ['createdAt' => 'DESC'], 10);
        $recentSales = 0.0;
        foreach ($recentOrders as $order) {
            $recentSales += (float) $order->getTotalPrice();
        }

        return $this->json([
            'isAdmin' => $isAdmin,
            'isStaff' => $this->isGranted('ROLE_STAFF'),
            'totalProducts' => $products->count([]),
            'totalCategories' => $categories->count([]),
            'totalOrders' => $orders->count([]),
            'totalUsers' => $users->count([]),
            'recentSales' => $recentSales,
            'recentOrders' => array_map(fn (Order $o) => $this->serializeOrder($o), $recentOrders),
            'recentProducts' => array_map(
                fn (Product $p) => $this->serializeProduct($p),
                $products->findBy([], ['createdAt' => 'DESC'], 10),
            ),
            'recentLogs' => $isAdmin
                ? array_map(fn (Log $l) => $this->serializeLog($l), $logs->findBy([], ['createdAt' => 'DESC'], 10))
                : [],
        ]);
    }

    // —— Products ——

    #[Route('/products', name: 'api_admin_products_list', methods: ['GET'])]
    public function productsList(ProductRepository $repo): JsonResponse
    {
        $items = array_map(fn (Product $p) => $this->serializeProduct($p), $repo->findBy([], ['name' => 'ASC']));

        return $this->json(['items' => $items]);
    }

    #[Route('/products/{id}', name: 'api_admin_products_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function productsShow(Product $product): JsonResponse
    {
        return $this->json($this->serializeProduct($product));
    }

    #[Route('/products', name: 'api_admin_products_create', methods: ['POST'])]
    public function productsCreate(Request $request): JsonResponse
    {
        $data = $this->decodeJson($request);
        $product = new Product();
        $this->applyProductData($product, $data, true);
        $product->setCreatedBy($this->requireUser());

        $this->em->persist($product);
        $this->em->flush();
        $this->logService->log('CREATE', 'Product', "Created new product: {$product->getName()}");

        return $this->json($this->serializeProduct($product), Response::HTTP_CREATED);
    }

    #[Route('/products/{id}', name: 'api_admin_products_update', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function productsUpdate(Product $product, Request $request): JsonResponse
    {
        $this->applyProductData($product, $this->decodeJson($request), false);
        $this->em->flush();
        $this->logService->log('UPDATE', 'Product', "Updated product: {$product->getName()}");

        return $this->json($this->serializeProduct($product));
    }

    #[Route('/products/{id}', name: 'api_admin_products_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function productsDelete(Product $product): JsonResponse
    {
        try {
            $name = $product->getName();
            $this->em->remove($product);
            $this->em->flush();
            $this->logService->log('DELETE', 'Product', "Deleted product: {$name}");
        } catch (ForeignKeyConstraintViolationException) {
            return $this->json(['error' => 'Cannot delete: product is linked to orders.'], Response::HTTP_CONFLICT);
        }

        return $this->json(['message' => 'Product deleted']);
    }

    // —— Categories ——

    #[Route('/categories', name: 'api_admin_categories_list', methods: ['GET'])]
    public function categoriesList(CategoryRepository $repo): JsonResponse
    {
        return $this->json([
            'items' => array_map(fn (Category $c) => $this->serializeCategory($c), $repo->findBy([], ['name' => 'ASC'])),
        ]);
    }

    #[Route('/categories/{id}', name: 'api_admin_categories_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function categoriesShow(Category $category): JsonResponse
    {
        return $this->json($this->serializeCategory($category));
    }

    #[Route('/categories', name: 'api_admin_categories_create', methods: ['POST'])]
    public function categoriesCreate(Request $request): JsonResponse
    {
        $data = $this->decodeJson($request);
        $category = new Category();
        $category->setName((string) ($data['name'] ?? ''));
        if (isset($data['description'])) {
            $category->setDescription($data['description'] !== '' ? (string) $data['description'] : null);
        }
        $category->setCreatedBy($this->requireUser());

        $this->em->persist($category);
        $this->em->flush();
        $this->logService->log('CREATE', 'Category', "Created category: {$category->getName()}");

        return $this->json($this->serializeCategory($category), Response::HTTP_CREATED);
    }

    #[Route('/categories/{id}', name: 'api_admin_categories_update', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function categoriesUpdate(Category $category, Request $request): JsonResponse
    {
        $data = $this->decodeJson($request);
        if (isset($data['name'])) {
            $category->setName((string) $data['name']);
        }
        if (array_key_exists('description', $data)) {
            $category->setDescription($data['description'] !== '' && $data['description'] !== null ? (string) $data['description'] : null);
        }
        $this->em->flush();
        $this->logService->log('UPDATE', 'Category', "Updated category: {$category->getName()}");

        return $this->json($this->serializeCategory($category));
    }

    #[Route('/categories/{id}', name: 'api_admin_categories_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function categoriesDelete(Category $category): JsonResponse
    {
        $name = $category->getName();
        $this->em->remove($category);
        $this->em->flush();
        $this->logService->log('DELETE', 'Category', "Deleted category: {$name}");

        return $this->json(['message' => 'Category deleted']);
    }

    // —— Orders ——

    #[Route('/orders', name: 'api_admin_orders_list', methods: ['GET'])]
    public function ordersList(OrderRepository $repo): JsonResponse
    {
        return $this->json([
            'items' => array_map(fn (Order $o) => $this->serializeOrder($o), $repo->findBy([], ['createdAt' => 'DESC'])),
            'statuses' => self::ORDER_STATUSES,
        ]);
    }

    #[Route('/orders/{id}', name: 'api_admin_orders_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function ordersShow(Order $order): JsonResponse
    {
        return $this->json([
            'order' => $this->serializeOrder($order, true),
            'statuses' => self::ORDER_STATUSES,
        ]);
    }

    #[Route('/orders/{id}/status', name: 'api_admin_orders_status', requirements: ['id' => '\d+'], methods: ['PATCH', 'PUT'])]
    public function ordersStatus(Order $order, Request $request): JsonResponse
    {
        $data = $this->decodeJson($request);
        $newStatus = trim((string) ($data['status'] ?? ''));
        if (!in_array($newStatus, self::ORDER_STATUSES, true)) {
            return $this->json(['error' => 'Invalid status'], Response::HTTP_BAD_REQUEST);
        }

        $oldStatus = $order->getStatus();
        $order->setStatus($newStatus);
        $this->em->flush();
        $this->logService->log('UPDATE', 'Order', "Updated order #{$order->getId()} status from {$oldStatus} to {$newStatus}");

        return $this->json($this->serializeOrder($order, true));
    }

    #[Route('/orders/{id}', name: 'api_admin_orders_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function ordersDelete(Order $order): JsonResponse
    {
        $id = $order->getId();
        $this->em->remove($order);
        $this->em->flush();
        $this->logService->log('DELETE', 'Order', "Deleted order #{$id}");

        return $this->json(['message' => 'Order deleted']);
    }

    // —— Stock ——

    #[Route('/stock', name: 'api_admin_stock_list', methods: ['GET'])]
    public function stockList(Request $request, StockMovementRepository $repo): JsonResponse
    {
        $fromInput = $request->query->get('from');
        $toInput = $request->query->get('to');
        $from = is_string($fromInput) && $fromInput !== '' ? (new \DateTimeImmutable($fromInput))->setTime(0, 0, 0) : null;
        $to = is_string($toInput) && $toInput !== '' ? (new \DateTimeImmutable($toInput))->setTime(23, 59, 59) : null;
        $productId = $request->query->getInt('product') ?: null;
        $userFilter = $request->query->get('user');

        $movements = $repo->findFiltered(
            is_string($userFilter) ? $userFilter : null,
            $productId,
            $from,
            $to,
        );

        return $this->json([
            'items' => array_map(fn (StockMovement $m) => $this->serializeStock($m), $movements),
        ]);
    }

    #[Route('/stock', name: 'api_admin_stock_create', methods: ['POST'])]
    public function stockCreate(Request $request, ProductRepository $products): JsonResponse
    {
        $data = $this->decodeJson($request);
        $productId = (int) ($data['productId'] ?? 0);
        $amount = (int) ($data['amount'] ?? 0);

        if ($productId < 1 || $amount < 1) {
            return $this->json(['error' => 'Product and valid quantity are required'], Response::HTTP_BAD_REQUEST);
        }

        $product = $products->find($productId);
        if (!$product instanceof Product) {
            return $this->json(['error' => 'Product not found'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->requireUser();
        $movement = new StockMovement();
        $movement->setAmount($amount);
        $movement->setProduct($product);
        $movement->setCreatedBy($user);

        $current = $product->getQuantity() ?? 0;
        $product->setQuantity($current + $amount);

        $this->em->persist($movement);
        $this->em->flush();

        $roleLabel = $this->isGranted('ROLE_ADMIN') ? 'Admin' : 'Staff';
        $actorName = $user->getName() ?? $user->getUserIdentifier();
        $this->logService->log(
            'STOCK_ADD',
            'Product',
            "{$roleLabel} {$actorName} added {$amount} stock to {$product->getName()} (new total: {$product->getQuantity()})",
        );

        return $this->json($this->serializeStock($movement), Response::HTTP_CREATED);
    }

    // —— Admin only: Users ——

    #[Route('/users', name: 'api_admin_users_list', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function usersList(UserRepository $repo): JsonResponse
    {
        return $this->json([
            'items' => array_map(fn (User $u) => $this->serializeUser($u), $repo->findBy([], ['name' => 'ASC'])),
        ]);
    }

    #[Route('/users/{id}', name: 'api_admin_users_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function usersShow(User $user): JsonResponse
    {
        return $this->json($this->serializeUser($user));
    }

    #[Route('/users', name: 'api_admin_users_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function usersCreate(Request $request, UserPasswordHasherInterface $hasher): JsonResponse
    {
        $data = $this->decodeJson($request);
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        if ($email === '' || $password === '') {
            return $this->json(['error' => 'Email and password are required'], Response::HTTP_BAD_REQUEST);
        }

        if ($this->em->getRepository(User::class)->findOneBy(['email' => $email])) {
            return $this->json(['error' => 'Email already exists'], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setEmail($email);
        $user->setName((string) ($data['name'] ?? explode('@', $email)[0]));
        $user->setPassword($hasher->hashPassword($user, $password));
        $user->setRoles($this->normalizeRoles($data['roles'] ?? ['ROLE_STAFF']));
        $user->setIsActive((bool) ($data['isActive'] ?? true));
        $user->setIsVerified(true);

        $this->em->persist($user);
        $this->em->flush();

        return $this->json($this->serializeUser($user), Response::HTTP_CREATED);
    }

    #[Route('/users/{id}', name: 'api_admin_users_update', requirements: ['id' => '\d+'], methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function usersUpdate(User $user, Request $request, UserPasswordHasherInterface $hasher): JsonResponse
    {
        $data = $this->decodeJson($request);
        if (isset($data['name'])) {
            $user->setName((string) $data['name']);
        }
        if (isset($data['email'])) {
            $user->setEmail((string) $data['email']);
        }
        if (isset($data['roles'])) {
            $user->setRoles($this->normalizeRoles($data['roles']));
        }
        if (array_key_exists('isActive', $data)) {
            $user->setIsActive((bool) $data['isActive']);
        }
        if (!empty($data['password'])) {
            $user->setPassword($hasher->hashPassword($user, (string) $data['password']));
        }
        $this->em->flush();

        return $this->json($this->serializeUser($user));
    }

    #[Route('/users/{id}/toggle', name: 'api_admin_users_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function usersToggle(User $user): JsonResponse
    {
        $user->setIsActive(!$user->isActive());
        $this->em->flush();

        return $this->json($this->serializeUser($user));
    }

    // —— Admin only: Logs ——

    #[Route('/logs', name: 'api_admin_logs_list', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function logsList(Request $request, LogRepository $repo): JsonResponse
    {
        $qb = $repo->createQueryBuilder('l')->orderBy('l.createdAt', 'DESC')->setMaxResults(200);
        if ($action = $request->query->get('action')) {
            $qb->andWhere('l.action = :a')->setParameter('a', $action);
        }

        return $this->json([
            'items' => array_map(fn (Log $l) => $this->serializeLog($l), $qb->getQuery()->getResult()),
        ]);
    }

    #[Route('/logs/{id}', name: 'api_admin_logs_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function logsShow(Log $log): JsonResponse
    {
        return $this->json($this->serializeLog($log));
    }

    #[Route('/customers', name: 'api_admin_customers_list', methods: ['GET'])]
    public function customersList(UserRepository $repo): JsonResponse
    {
        $all = $repo->findBy([], ['name' => 'ASC']);
        $customers = array_filter($all, static function (User $u): bool {
            $roles = $u->getRoles();

            return !in_array('ROLE_ADMIN', $roles, true) && !in_array('ROLE_STAFF', $roles, true);
        });

        return $this->json([
            'items' => array_map(fn (User $u) => [
                'id' => $u->getId(),
                'name' => $u->getName(),
                'email' => $u->getEmail(),
            ], array_values($customers)),
        ]);
    }

    // —— Serialization ——

    private function serializeProduct(Product $p): array
    {
        $cat = $p->getCategory();

        return [
            'id' => $p->getId(),
            'name' => $p->getName(),
            'price' => $p->getPrice(),
            'quantity' => $p->getQuantity(),
            'description' => $p->getDescription(),
            'image' => $p->getImage(),
            'createdAt' => $p->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'category' => $cat ? ['id' => $cat->getId(), 'name' => $cat->getName()] : null,
        ];
    }

    private function serializeCategory(Category $c): array
    {
        return [
            'id' => $c->getId(),
            'name' => $c->getName(),
            'description' => $c->getDescription(),
        ];
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
            ];
        }

        $data = [
            'id' => $o->getId(),
            'customerName' => $o->getCustomerName(),
            'totalPrice' => $o->getTotalPrice(),
            'status' => $o->getStatus(),
            'createdAt' => $o->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'placedBy' => $o->getPlacedBy() ? [
                'id' => $o->getPlacedBy()->getId(),
                'name' => $o->getPlacedBy()->getName(),
                'email' => $o->getPlacedBy()->getEmail(),
            ] : null,
            'itemCount' => count($items),
        ];

        if ($detailed) {
            $data['items'] = $items;
        }

        return $data;
    }

    private function serializeStock(StockMovement $m): array
    {
        $p = $m->getProduct();
        $u = $m->getCreatedBy();

        return [
            'id' => $m->getId(),
            'amount' => $m->getAmount(),
            'createdAt' => $m->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'product' => $p ? ['id' => $p->getId(), 'name' => $p->getName(), 'quantity' => $p->getQuantity()] : null,
            'createdBy' => $u ? ['id' => $u->getId(), 'name' => $u->getName(), 'email' => $u->getEmail()] : null,
        ];
    }

    private function serializeUser(User $u): array
    {
        return [
            'id' => $u->getId(),
            'name' => $u->getName(),
            'email' => $u->getEmail(),
            'roles' => array_values(array_filter($u->getRoles(), fn (string $r) => $r !== 'ROLE_USER')),
            'isActive' => $u->isActive(),
            'createdAt' => $u->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function serializeLog(Log $l): array
    {
        return [
            'id' => $l->getId(),
            'action' => $l->getAction(),
            'message' => $l->getMessage(),
            'status' => $l->getStatus(),
            'userName' => $l->getUserName(),
            'userRole' => $l->getUserRole(),
            'entity' => $l->getEntity(),
            'createdAt' => $l->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function applyProductData(Product $product, array $data, bool $isNew): void
    {
        if (isset($data['name'])) {
            $product->setName((string) $data['name']);
        }
        if (isset($data['price'])) {
            $product->setPrice((float) $data['price']);
        }
        if ($isNew && isset($data['quantity'])) {
            $product->setQuantity((int) $data['quantity']);
        }
        if (array_key_exists('description', $data)) {
            $product->setDescription($data['description'] !== null && $data['description'] !== '' ? (string) $data['description'] : null);
        }
        if (isset($data['categoryId'])) {
            $cat = $this->em->getRepository(Category::class)->find((int) $data['categoryId']);
            $product->setCategory($cat instanceof Category ? $cat : null);
        }
    }

    /** @return list<string> */
    private function normalizeRoles(mixed $roles): array
    {
        if (!is_array($roles)) {
            $roles = [$roles];
        }
        $out = [];
        foreach ($roles as $r) {
            if ($r === 'ROLE_ADMIN' || $r === 'ROLE_STAFF' || $r === 'Admin' || $r === 'Staff') {
                $out[] = $r === 'Admin' || $r === 'ROLE_ADMIN' ? 'ROLE_ADMIN' : 'ROLE_STAFF';
            }
        }
        if ($out === []) {
            $out[] = 'ROLE_STAFF';
        }

        return array_values(array_unique($out));
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function decodeJson(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : [];
    }
}
