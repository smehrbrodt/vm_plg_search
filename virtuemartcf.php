<?php
/**
 *
 * Search plugin for virtuemart.
 * Based on the official Virtuemart search plugin.
 *
 * @copyright Copyright (C) 2004-2008 soeren - All rights reserved.
 *                          2014 Samuel Mehrbrodt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE
 * VirtueMart is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 */

defined('_JEXEC') or die('Restricted access');


class plgSearchVirtuemartCF extends JPlugin {
    /**
     * @return array An array of search areas
     */
    function onContentSearchAreas () {
        $this->loadLanguage();
        static $areas = array(
            'virtuemartcf' => 'PLG_SEARCH_VIRTUEMART_CF_PRODUCTS'
        );
        return $areas;
    }

    /**
     * Content Search method
     * The sql must return the following fields that are used in a common display
     * routine: href, title, section, created, text, browsernav
     *
     * @param string Target search string
     * @param string mathcing option, exact|any|all
     * @param string ordering option, newest|oldest|popular|alpha|category
     * @param mixed An array if the search it to be restricted to areas, null if search all
     *
     * @return array An array of database result objects
     */
    function onContentSearch ($text, $phrase = '', $ordering = '', $areas = NULL) {
        $db = JFactory::getDbo();

        if (is_array($areas)) {
            if (!array_intersect ($areas, array_keys ($this->onContentSearchAreas()))) {
                return array();
            }
        }

        $limit = $this->params->def('search_limit', 50);

        if (!class_exists('VmConfig')) {
            require(JPATH_ADMINISTRATOR . DS . 'components' . DS . 'com_virtuemart' . DS . 'helpers' . DS . 'config.php');
        }
        VmConfig::loadConfig();

        $text = trim($text);
        if (empty($text))
            return array();

        switch ($phrase) {
            case 'exact':
                $text = $db->Quote ('%' . $db->escape($text, TRUE) . '%', FALSE);
                $wheres2 = array();
                $wheres2[] = 'p.product_sku LIKE ' . $text;
                $wheres2[] = 'a.product_name LIKE ' . $text;
                $wheres2[] = 'a.product_s_desc LIKE ' . $text;
                $wheres2[] = 'a.product_desc LIKE ' . $text;
                $wheres2[] = 'b.category_name LIKE ' . $text;
                $wheres2[] = 'cf.custom_value LIKE ' . $text;
                $where = '(' . implode (') OR (', $wheres2) . ')';
                break;
            case 'all':
            case 'any':
            default:
                $words = explode (' ', $text);
                $wheres = array();
                foreach ($words as $word) {
                    $word = $db->Quote('%' . $db->escape($word, TRUE) . '%', FALSE);
                    $wheres2 = array();
                    $wheres2[] = 'p.product_sku LIKE ' . $word;
                    $wheres2[] = 'a.product_name LIKE ' . $word;
                    $wheres2[] = 'a.product_s_desc LIKE ' . $word;
                    $wheres2[] = 'a.product_desc LIKE ' . $word;
                    $wheres2[] = 'b.category_name LIKE ' . $word;
                    $wheres2[] = 'cf.custom_value LIKE ' . $word;
                    $wheres[] = implode (' OR ', $wheres2);
                }
                $where = '(' . implode (($phrase == 'all' ? ') AND (' : ') OR ('), $wheres) . ')';
                break;
        }
        switch($ordering) {
            case 'alpha':
                $order = 'a.product_name ASC';
                break;
            case 'category':
                $order = 'b.category_name ASC, a.product_name ASC';
                break;
            case 'popular':
                $order = 'a.product_name ASC';
                break;
            case 'newest':
                $order = 'p.created_on DESC';
                break;
            case 'oldest':
                $order = 'p.created_on ASC';
                break;
            default:
                $order = 'a.product_name ASC';
        }

        $where_shopper_group="";
        $currentVMuser = VmModel::getModel('user')->getUser();
        $virtuemart_shoppergroup_ids = (array)$currentVMuser->shopper_groups;

        if (is_array($virtuemart_shoppergroup_ids)) {
            $sgrgroups = array();
            foreach($virtuemart_shoppergroup_ids as $virtuemart_shoppergroup_id) {
                $sgrgroups[] = 'psgr.`virtuemart_shoppergroup_id`= "' . (int)$virtuemart_shoppergroup_id . '" ';
            }
            $sgrgroups[] = 'psgr.`virtuemart_shoppergroup_id` IS NULL ';
            $where_shopper_group = "( " . implode (' OR ', $sgrgroups) . " ) ";
        }

        $query = "
                SELECT DISTINCT
                    CONCAT( a.product_name,' (',p.product_sku,')' ) AS title,
                    a.virtuemart_product_id,
                    b.virtuemart_category_id,
                    a.product_s_desc AS text,
                    b.category_name as section,
                    p.created_on as created,
                    '2' AS browsernav,
                    cf.custom_value
                FROM `#__virtuemart_products_" . VMLANG . "` AS a
                JOIN #__virtuemart_products as p using (`virtuemart_product_id`)
                LEFT JOIN `#__virtuemart_product_categories` AS xref
                        ON xref.`virtuemart_product_id` = a.`virtuemart_product_id`
                LEFT JOIN `#__virtuemart_categories_" . VMLANG . "` AS b
                        ON b.`virtuemart_category_id` = xref.`virtuemart_category_id`
                LEFT JOIN `#__virtuemart_product_shoppergroups` as `psgr`
                        ON (`psgr`.`virtuemart_product_id`=`a`.`virtuemart_product_id`)
                LEFT JOIN `#__virtuemart_product_customfields` AS cf
                        ON cf.virtuemart_product_id = a.virtuemart_product_id
                LEFT JOIN `#__virtuemart_customs` AS customs
                        ON customs.virtuemart_custom_id = cf.virtuemart_customfield_id
                WHERE
                        {$where}
                        and p.published=1
                        AND $where_shopper_group"
            . (VmConfig::get('show_uncat_child_products') ? '' : ' and b.virtuemart_category_id>0 ')
            . ' ORDER BY ' . $order;
        $db->setQuery($query, 0, $limit);

        $rows = $db->loadObjectList();
        if ($rows) {
            foreach ($rows as $key => $row) {
                $rows[$key]->href = 'index.php?option=com_virtuemart&view=productdetails&virtuemart_product_id=' .
                    $row->virtuemart_product_id . '&virtuemart_category_id=' . $row->virtuemart_category_id;
            }
        }
        return $rows;
    }
}
