<?php
namespace Vindi\VP\Setup\Patch\Schema;

use Magento\Framework\Setup\Patch\PatchVersionInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\InstallSchemaInterface;

class CreatePixQueueTable implements PatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    public function apply()
    {
        $setup = $this->moduleDataSetup;
        $setup->getConnection()->startSetup();

        if (!$setup->getConnection()->isTableExists($setup->getTable('vindi_vp_pix_queue'))) {
            $table = $setup->getConnection()
                ->newTable($setup->getTable('vindi_vp_pix_queue'))
                ->addColumn('entity_id', \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER, null, [
                    'identity' => true,
                    'unsigned' => true,
                    'nullable' => false,
                    'primary' => true,
                ], 'ID')
                ->addColumn('order_id', \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER, null, [
                    'nullable' => false,
                    'unsigned' => true,
                ], 'Order ID')
                ->addColumn('payment_id_cc', \Magento\Framework\DB\Ddl\Table::TYPE_TEXT, 64, [
                    'nullable' => false,
                ], 'Cartão Payment ID')
                ->addColumn('amount_pix', \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER, null, [
                    'nullable' => false,
                ], 'Valor Pix (centavos)')
                ->addColumn('payment_meta', \Magento\Framework\DB\Ddl\Table::TYPE_TEXT, '2M', [
                    'nullable' => true,
                ], 'Metadados Pix (JSON)')
                ->addColumn('status', \Magento\Framework\DB\Ddl\Table::TYPE_TEXT, 32, [
                    'nullable' => false,
                    'default' => 'pending',
                ], 'Status')
                ->addColumn('attempts', \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER, null, [
                    'nullable' => false,
                    'default' => 0,
                ], 'Tentativas')
                ->addColumn('created_at', \Magento\Framework\DB\Ddl\Table::TYPE_TIMESTAMP, null, [
                    'nullable' => false,
                    'default' => \Magento\Framework\DB\Ddl\Table::TIMESTAMP_INIT,
                ], 'Criado em')
                ->addColumn('updated_at', \Magento\Framework\DB\Ddl\Table::TYPE_TIMESTAMP, null, [
                    'nullable' => false,
                    'default' => \Magento\Framework\DB\Ddl\Table::TIMESTAMP_INIT_UPDATE,
                ], 'Atualizado em');
            $setup->getConnection()->createTable($table);
        }

        $setup->getConnection()->endSetup();
    }

    public static function getDependencies()
    {
        return [];
    }

    public function getAliases()
    {
        return [];
    }
}
