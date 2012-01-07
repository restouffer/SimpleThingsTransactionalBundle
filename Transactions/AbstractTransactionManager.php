<?php
/**
 * SimpleThings TransactionalBundle
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to kontakt@beberlei.de so I can send you a copy immediately.
 */

namespace SimpleThings\TransactionalBundle\Transactions;

use SimpleThings\TransactionalBundle\TransactionException;

abstract class AbstractTransactionManager implements TransactionManagerInterface
{
    /**
     * @var SplStack
     */
    private $transactions = array();

    /**
     * @var TransactionStatus
     */
    private $currentTxStatus;

    public function __construct()
    {
        $this->transactions = new \SplObjectStorage();
    }

    abstract protected function doBeginTransaction(TransactionDefinition $def);

    abstract protected function doCommit(TransactionStatus $def);

    abstract protected function doRollBack(TransactionStatus $def);

    protected function beginTransaction(TransactionDefinition $def)
    {
        $status = $this->doBeginTransaction($def);
        $this->transactions->attach($status);
        $this->transactions[$status] = $def;
        $this->currentTxStatus = $status;
        return $status;
    }

    public function getTransaction(TransactionDefinition $def)
    {
        switch ($def->getPropagation()) {
            case TransactionDefinition::PROPAGATION_REQUIRES_NEW:
                $status = $this->beginTransaction($def);
                break;
            case TransactionDefinition::PROPAGATION_NEVER:
                if (count($this->transactions)) {
                    throw new TransactionException("Controller does not want to run in transaction, but one is open.");
                }
                return null;
            case TransactionDefinition::PROPAGATION_REQUIRED:
                $openTransactionDef = $this->getCurrentTransactionDef();
                $status = $this->getCurrentTransaction();
                if ($openTransactionDef) {
                    if ($def->getIsolationLevel() != $openTransactionDef->getIsolationLevel()) {
                        throw new TransactionException("Trying to re-use transaction that has different isolation level than the already active one.");
                    }

                    if ($status->isReadOnly() && ! $def->getReadOnly()) {
                        throw new TransactionException("Cannot reuse readonly transaction when requesting a read/write transaction.");
                    }
                }

                if (!$status) {
                    $status = $this->beginTransaction($def);
                }
                break;
            case TransactionDefinition::PROPAGATION_SUPPORTS:
            default:
                $status = $this->getCurrentTransaction();
                break;
        }
        return $status;
    }

    public function commit(TransactionStatus $status)
    {
        if ($status->isCompleted()) {
            throw new TransactionException("Cannot commit an already completed transaction.");
        } else if (!$this->transactions->contains($status)) {
            throw new TransactionException("Cannot commit a detached transaction. It may have been committed before or belongs to another transaction manager");
        }

        if ($status->isRollBackOnly()) {
            return $this->rollBack($status);
        }

        $this->doCommit($status);
        $this->transactions->detach($status);
    }

    public function rollBack(TransactionStatus $status)
    {
        if ($status->isCompleted()) {
            throw new TransactionException("Cannot rollback an already completed transaction.");
        } else if (!$this->transactions->contains($status)) {
            throw new TransactionException("Cannot rollback a detached transaction. It may have been committed/rollbacked before or belongs to another transaction manager");
        }

        $this->doRollBack($status);
        $this->transactions->detach($status);
    }

    private function getCurrentTransaction()
    {
        return $this->currentTxStatus;
    }

    private function getCurrentTransactionDef()
    {
        if ($this->currentTxStatus) {
            return $this->transactions[$this->currentTxStatus];
        }
        return null;
    }
}


