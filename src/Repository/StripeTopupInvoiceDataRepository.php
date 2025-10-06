<?php

namespace App\Repository;

use App\Entity\StripeTopupInvoiceData;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method StripeTopupInvoiceData|null find($id, $lockMode = null, $lockVersion = null)
 * @method StripeTopupInvoiceData|null findOneBy(array $criteria, array $orderBy = null)
 * @method StripeTopupInvoiceData[] findAll()
 * @method StripeTopupInvoiceData[] findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class StripeTopupInvoiceDataRepository extends ServiceEntityRepository
{
    /**
     * @param ManagerRegistry $registry
     */
    public function __construct(
        ManagerRegistry $registry
    ) {
        parent::__construct($registry, StripeTopupInvoiceData::class);
    }

    public function persistAndFlush(StripeTopupInvoiceData $stripeTopupInvoiceData): StripeTopupInvoiceData
    {
        $this->getEntityManager()->persist($stripeTopupInvoiceData);
        $this->getEntityManager()->flush();

        return $stripeTopupInvoiceData;
    }

    public function persist(StripeTopupInvoiceData $stripeTopupInvoiceData): StripeTopupInvoiceData
    {
        $this->getEntityManager()->persist($stripeTopupInvoiceData);

        return $stripeTopupInvoiceData;
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }
}
