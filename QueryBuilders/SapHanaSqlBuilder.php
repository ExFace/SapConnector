<?php
namespace exface\SapConnector\QueryBuilders;

use exface\Core\QueryBuilders\MySqlBuilder;

/**
 * SQL query builder for SAP HANA database
 *
 * This query builder is based on the MySQL syntax, which is mostly supported by SAP HANA.
 *
 * @author Andrej Kabachnik
 *        
 */
class SapHanaSqlBuilder extends MySqlBuilder
{

    /**
     * SAP HANA supports custom SQL statements in the GROUP BY clause.
     * The rest is similar to MySQL
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\QueryBuilders\AbstractSqlBuilder::buildSqlGroupBy()
     */
    protected function buildSqlGroupBy(\exface\Core\CommonLogic\QueryBuilder\QueryPart $qpart, $select_from = null)
    {
        $output = '';
        if ($this->isSqlStatement($qpart->getAttribute()->getDataAddress())) {
            if (is_null($select_from)) {
                $select_from = $qpart->getAttribute()->getRelationPath()->toString() ? $qpart->getAttribute()->getRelationPath()->toString() : $this->getMainObject()->getAlias();
            }
            $output = $this->replacePlaceholdersInSqlAddress($qpart->getAttribute()->getDataAddress(), $qpart->getAttribute()->getRelationPath(), ['~alias' => $select_from], $select_from);
        } else {
            $output = parent::buildSqlGroupBy($qpart, $select_from);
        }
        return $output;
    }
}
