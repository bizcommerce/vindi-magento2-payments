<?php
declare(strict_types=1);

/**
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Vindi
 * @package     Vindi_VP
 */

namespace Vindi\VP\Setup\Patch\Data;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Class CreateMultiPaymentQueueTable
 * Creates table for managing multi-payment method secondary requests
 */
class CreateMultiPaymentQueueTable implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    /**
     * Apply patch
     *
     * @return void
     */
    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $tableName = $this->moduleDataSetup->getTable('vindi_vp_multi_payment_queue');

        if (!$this->moduleDataSetup->getConnection()->isTableExists($tableName)) {
            $table = $this->moduleDataSetup->getConnection()
                ->newTable($tableName)
                ->addColumn(
                    'entity_id',
                    Table::TYPE_INTEGER,
                    null,
                    ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true],
                    'Entity ID'
                )
                ->addColumn(
                    'order_id',
                    Table::TYPE_INTEGER,
                    null,
                    ['unsigned' => true, 'nullable' => false],
                    'Order ID'
                )
                ->addColumn(
                    'increment_id',
                    Table::TYPE_TEXT,
                    32,
                    ['nullable' => false],
                    'Order Increment ID'
                )
                ->addColumn(
                    'payment_method',
                    Table::TYPE_TEXT,
                    64,
                    ['nullable' => false],
                    'Payment Method Code'
                )
                ->addColumn(
                    'primary_transaction_id',
                    Table::TYPE_TEXT,
                    255,
                    ['nullable' => true],
                    'Primary Transaction ID'
                )
                ->addColumn(
                    'secondary_method_type',
                    Table::TYPE_TEXT,
                    32,
                    ['nullable' => false],
                    'Secondary Method Type (pix, card, bankslip)'
                )
                ->addColumn(
                    'secondary_amount',
                    Table::TYPE_DECIMAL,
                    '12,4',
                    ['nullable' => false, 'default' => '0.0000'],
                    'Secondary Payment Amount'
                )
                ->addColumn(
                    'request_data',
                    Table::TYPE_TEXT,
                    '2M',
                    ['nullable' => false],
                    'Serialized Request Data'
                )
                ->addColumn(
                    'status',
                    Table::TYPE_TEXT,
                    32,
                    ['nullable' => false, 'default' => 'pending'],
                    'Status (pending, processing, completed, failed)'
                )
                ->addColumn(
                    'attempts',
                    Table::TYPE_INTEGER,
                    null,
                    ['unsigned' => true, 'nullable' => false, 'default' => 0],
                    'Processing Attempts'
                )
                ->addColumn(
                    'max_attempts',
                    Table::TYPE_INTEGER,
                    null,
                    ['unsigned' => true, 'nullable' => false, 'default' => 3],
                    'Maximum Processing Attempts'
                )
                ->addColumn(
                    'next_attempt_at',
                    Table::TYPE_TIMESTAMP,
                    null,
                    ['nullable' => true],
                    'Next Attempt Timestamp'
                )
                ->addColumn(
                    'response_data',
                    Table::TYPE_TEXT,
                    '2M',
                    ['nullable' => true],
                    'Serialized Response Data'
                )
                ->addColumn(
                    'error_message',
                    Table::TYPE_TEXT,
                    '64k',
                    ['nullable' => true],
                    'Error Message'
                )
                ->addColumn(
                    'created_at',
                    Table::TYPE_TIMESTAMP,
                    null,
                    ['nullable' => false, 'default' => Table::TIMESTAMP_INIT],
                    'Created At'
                )
                ->addColumn(
                    'updated_at',
                    Table::TYPE_TIMESTAMP,
                    null,
                    ['nullable' => false, 'default' => Table::TIMESTAMP_INIT_UPDATE],
                    'Updated At'
                )
                ->addIndex(
                    $this->moduleDataSetup->getIdxName('vindi_vp_multi_payment_queue', ['order_id']),
                    ['order_id']
                )
                ->addIndex(
                    $this->moduleDataSetup->getIdxName('vindi_vp_multi_payment_queue', ['status']),
                    ['status']
                )
                ->addIndex(
                    $this->moduleDataSetup->getIdxName('vindi_vp_multi_payment_queue', ['payment_method']),
                    ['payment_method']
                )
                ->addIndex(
                    $this->moduleDataSetup->getIdxName('vindi_vp_multi_payment_queue', ['next_attempt_at']),
                    ['next_attempt_at']
                )
                ->addForeignKey(
                    $this->moduleDataSetup->getFkName('vindi_vp_multi_payment_queue', 'order_id', 'sales_order', 'entity_id'),
                    'order_id',
                    $this->moduleDataSetup->getTable('sales_order'),
                    'entity_id',
                    Table::ACTION_CASCADE
                )
                ->setComment('Vindi VP Multi Payment Queue Table');

            $this->moduleDataSetup->getConnection()->createTable($table);
        }

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * Get dependencies
     *
     * @return array
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * Get aliases
     *
     * @return array
     */
    public function getAliases()
    {
        return [];
    }
}
