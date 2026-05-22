<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Entity\Category;
use App\Entity\Order;
use App\Entity\Log;
use App\Repository\ProductRepository;
use App\Repository\CategoryRepository;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{


    public function index(): Response
    {
        // Redirect to unified dashboard (admin will see full data)
        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    public function dashboard(
        ProductRepository $productRepository,
        CategoryRepository $categoryRepository,
        OrderRepository $orderRepository
    ): Response
    {
        // Get dashboard statistics
        $totalProducts = count($productRepository->findAll());
        $totalCategories = count($categoryRepository->findAll());
        $totalOrders = count($orderRepository->findAll());
        
                
        // Get recent products
        $recentProducts = $productRepository->createQueryBuilder('p')
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        return $this->render('admin/dashboard.html.twig', [
            'total_products' => $totalProducts,
            'total_categories' => $totalCategories,
            'total_orders' => $totalOrders,
            'total_users' => 0, // You can implement user counting later
                        'recent_products' => $recentProducts,
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('GearGrid Admin');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');

        yield MenuItem::section('Management');

        // yield MenuItem::linkToCrud('Products', 'fa fa-box', Product::class);
        // yield MenuItem::linkToCrud('Categories', 'fa fa-tags', Category::class);
        // yield MenuItem::linkToCrud('Orders', 'fa fa-shopping-cart', Order::class);
        // yield MenuItem::linkToCrud('Logs', 'fa fa-file', Log::class);
            }
}
