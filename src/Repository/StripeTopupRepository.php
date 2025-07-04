<?php

namespace App\Repository;

use App\Entity\StripeTopup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method StripeTopup|null find($id, $lockMode = null, $lockVersion = null)
 * @method StripeTopup|null findOneBy(array $criteria, array $orderBy = null)
 * @method StripeTopup[]    findAll()
 * @method StripeTopup[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class StripeTopupRepository extends ServiceEntityRepository
{
    /**
     * @param ManagerRegistry $registry
     */
    public function __construct(
        ManagerRegistry $registry
    ) {
        parent::__construct($registry, StripeTopup::class);
    }

    public function persistAndFlush(StripeTopup $stripeTopup): StripeTopup
    {
        $this->getEntityManager()->persist($stripeTopup);
        $this->getEntityManager()->flush();

        return $stripeTopup;
    }

    public function persist(StripeTopup $stripeTopup): StripeTopup
    {
        $this->getEntityManager()->persist($stripeTopup);

        return $stripeTopup;
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }
}
