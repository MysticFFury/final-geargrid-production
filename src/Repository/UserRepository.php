<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * Clear foreign keys pointing at this user so the row can be deleted without losing orders/products.
     */
    public function detachUserReferences(User $user): void
    {
        $em = $this->getEntityManager();

        $updates = [
            [\App\Entity\Order::class, 'placedBy'],
            [\App\Entity\Order::class, 'createdBy'],
            [\App\Entity\Product::class, 'createdBy'],
            [\App\Entity\Category::class, 'createdBy'],
            [\App\Entity\StockMovement::class, 'createdBy'],
        ];

        foreach ($updates as [$entityClass, $field]) {
            $em->createQueryBuilder()
                ->update($entityClass, 'e')
                ->set('e.' . $field, ':null')
                ->where('e.' . $field . ' = :user')
                ->setParameter('null', null)
                ->setParameter('user', $user)
                ->getQuery()
                ->execute();
        }
    }

//    /**
//     * @return User[] Returns an array of User objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('u')
//            ->andWhere('u.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('u.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?User
//    {
//        return $this->createQueryBuilder('u')
//            ->andWhere('u.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
