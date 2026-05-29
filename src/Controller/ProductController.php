<?php

namespace App\Controller;

use App\Entity\Product;
use App\Form\ProductType;
use App\Repository\ProductRepository;
use App\Service\LogService;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/product')]
final class ProductController extends AbstractController
{
    #[Route(name: 'app_product_index', methods: ['GET'])]
    public function index(ProductRepository $productRepository): Response
    {
        return $this->render('product/index.html.twig', [
            'products' => $productRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_product_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, SluggerInterface $slugger, LogService $logService): Response
    {
        $product = new Product();
        $form = $this->createForm(ProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile|null $imageFile */
            $imageFile = $form->get('imageFile')->getData();

            if ($imageFile) {
                $originalFilename = pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename.'-'.uniqid().'.'.$imageFile->guessExtension();

                try {
                    $imageFile->move($this->getParameter('products_directory'), $newFilename);
                } catch (FileException $e) {
                    $this->addFlash('error', 'Image upload failed: '.$e->getMessage());
                }
                $product->setImage($newFilename);
            }

            $product->setCreatedBy($this->getUser());
            $entityManager->persist($product);
            $entityManager->flush();

            // LOG THE ACTION
            $logService->log('CREATE', 'Product', "Created new product: {$product->getName()}");

            // Broadcast to WebSocket server
            try {
                $wsUrl = $_ENV['WEBSOCKET_SERVER_URL'] ?? 'http://127.0.0.1:8085/broadcast';
                $ch = curl_init($wsUrl);
                $payload = json_encode([
                    'event' => 'product-updated',
                    'data' => [
                        'productId' => $product->getId(),
                        'name' => $product->getName(),
                        'action' => 'create',
                        'message' => "New product {$product->getName()} added!"
                    ]
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                curl_exec($ch);
                curl_close($ch);
            } catch (\Exception $e) { }

            $this->addFlash('success', '✅ Product added successfully!');
            return $this->redirectToRoute('app_product_index');
        }

        return $this->render('product/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'app_product_show', methods: ['GET'])]
    public function show(Product $product): Response
    {
        return $this->render('product/show.html.twig', [
            'product' => $product,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_product_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Product $product, EntityManagerInterface $entityManager, SluggerInterface $slugger, LogService $logService): Response
    {
        $form = $this->createForm(ProductType::class, $product, ['edit_mode' => true]);
        $form->handleRequest($request);
        $oldImage = $product->getImage();

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile|null $imageFile */
            $imageFile = $form->get('imageFile')->getData();

            if ($imageFile) {
                if ($oldImage) {
                    $oldPath = $this->getParameter('products_directory').'/'.$oldImage;
                    if (file_exists($oldPath)) { @unlink($oldPath); }
                }
                $originalFilename = pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename.'-'.uniqid().'.'.$imageFile->guessExtension();

                try {
                    $imageFile->move($this->getParameter('products_directory'), $newFilename);
                } catch (FileException $e) { }

                $product->setImage($newFilename);
            } else {
                $product->setImage($oldImage);
            }

            $entityManager->flush();

            // LOG THE ACTION
            $logService->log('UPDATE', 'Product', "Updated product: {$product->getName()}");

            // Broadcast to WebSocket server
            try {
                $wsUrl = $_ENV['WEBSOCKET_SERVER_URL'] ?? 'http://127.0.0.1:8085/broadcast';
                $ch = curl_init($wsUrl);
                $payload = json_encode([
                    'event' => 'product-updated',
                    'data' => [
                        'productId' => $product->getId(),
                        'name' => $product->getName(),
                        'action' => 'update',
                        'message' => "Product {$product->getName()} has been updated!"
                    ]
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                curl_exec($ch);
                curl_close($ch);
            } catch (\Exception $e) { }

            $this->addFlash('success', '✅ Product updated successfully!');
            return $this->redirectToRoute('app_product_index');
        }

        return $this->render('product/edit.html.twig', [
            'product' => $product,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'app_product_delete', methods: ['POST'])]
    public function delete(Request $request, Product $product, EntityManagerInterface $entityManager, LogService $logService): Response
    {
        if ($this->isCsrfTokenValid('delete'.$product->getId(), $request->request->get('_token'))) {
            $productImage = $product->getImage();
            $productName = $product->getName();
            try {
                $entityManager->remove($product);
                $entityManager->flush();

                if ($productImage) {
                    $path = $this->getParameter('products_directory').'/'.$productImage;
                    if (file_exists($path)) { @unlink($path); }
                }

                $logService->log('DELETE', 'Product', "Deleted product: {$productName}");
                
                // Broadcast to WebSocket server
                try {
                    $wsUrl = $_ENV['WEBSOCKET_SERVER_URL'] ?? 'http://127.0.0.1:8085/broadcast';
                    $ch = curl_init($wsUrl);
                    $payload = json_encode([
                        'event' => 'product-updated',
                        'data' => [
                            'productId' => $product->getId(),
                            'name' => $productName,
                            'action' => 'delete',
                            'message' => "Product {$productName} has been removed."
                        ]
                    ]);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                    curl_exec($ch);
                    curl_close($ch);
                } catch (\Exception $e) { }
                
                $this->addFlash('success', '🗑️ Product deleted successfully!');
            } catch (ForeignKeyConstraintViolationException) {
                $this->addFlash('error', 'This product cannot be deleted because it is already used in existing orders.');
            }
        }

        return $this->redirectToRoute('app_product_index');
    }
}