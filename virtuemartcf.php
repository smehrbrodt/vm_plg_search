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


class PlgSearchVirtuemartCF extends JPlugin {
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
     * @param string $text Target search string
     * @param string $phrase matching option, exact|any|all
     * @param string $ordering ordering option, newest|oldest|popular|alpha|category
     * @param mixed  $areas An array if the search it to be restricted to areas, null if search all
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

        $limit = $this->params->get('search_limit', 50);
        switch($this->params->get('subtitledisplay', '1')) {
            case '1':
                $category_field = 'category_name';
                break;
            case '2':
                $category_field = 'customtitle';
                break;
        }
        $search_product_description = (bool) $this->params->get('enable_product_description_search', TRUE);
        $search_product_s_description = (bool) $this->params->get('enable_product_short_description_search', TRUE);
        $search_customfields = (bool) $this->params->get('enable_customfields', TRUE);
        $customfield_ids_condition = "";
        if ($search_customfields) {
            $value = trim($this->params->get('customfields', ""));

            // Remove all spaces
            $value = str_replace(' ', '', $value);
            if (!empty($value)){
                $customfield_ids = explode(",", $value);

                // Make sure we have only integers
                foreach($customfield_ids as &$id) {
                    $id = intval($id);
                }
                // The custom field ID must be either in the list specified or NULL.
                $customfield_ids_condition = "cf.virtuemart_custom_id IN (" .
                    implode(',', $customfield_ids) . ")";
            }

        }

        if (!class_exists('VmConfig')) {
            require(JPATH_ADMINISTRATOR . DS . 'components' . DS . 'com_virtuemart' . DS . 'helpers' . DS . 'config.php');
        }
        VmConfig::loadConfig();

        $text = trim($text);
        if (empty($text))
            return array();

        switch ($phrase) {
            case 'exact':
                $wheres2 = array();
                // product_sku should be exact match
                $wheres2[] = "p.product_sku=" . $db->quote($text, TRUE);
                $text = $db->quote("%$text%", TRUE);
                $wheres2[] = "a.product_name LIKE $text";
                $wheres2[] = "b.$category_field LIKE $text";
                if ($search_product_s_description)
                    $wheres2[] = "a.product_s_desc LIKE $text";
                if ($search_product_description)
                    $wheres2[] = "a.product_desc LIKE $text";
                if ($search_customfields)
                    $wheres2[] = "(cf.custom_value LIKE $text AND $customfield_ids_condition)";
                $where = '(' . implode (') OR (', $wheres2) . ')';
                break;
            case 'all':
            case 'any':
            default:
                $words = explode (' ', $text);
                $wheres = array();
                foreach ($words as $word) {
                    $wheres2 = array();
                    // product_sku should be exact match
                    $wheres2[] = "p.product_sku=" . $db->quote($word, TRUE);
                    $word = $db->quote("%$word%", TRUE);
                    $wheres2[] = "a.product_name LIKE $word";
                    $wheres2[] = "b.$category_field LIKE $word";
                    if ($search_product_s_description)
                        $wheres2[] = "a.product_s_desc LIKE $word";
                    if ($search_product_description)
                    $wheres2[] = "a.product_desc LIKE $word";
                    if ($search_customfields)
                        $wheres2[] = "(cf.custom_value LIKE $word AND $customfield_ids_condition)";

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

        $shopper_group_condition="";
        $currentVMuser = VmModel::getModel('user')->getUser();
        $virtuemart_shoppergroup_ids = (array)$currentVMuser->shopper_groups;

        if (is_array($virtuemart_shoppergroup_ids)) {
            $sgrgroups = array();
            foreach($virtuemart_shoppergroup_ids as $virtuemart_shoppergroup_id) {
                $sgrgroups[] = 'psgr.`virtuemart_shoppergroup_id`= "' . (int)$virtuemart_shoppergroup_id . '" ';
            }
            $sgrgroups[] = 'psgr.`virtuemart_shoppergroup_id` IS NULL ';
            $shopper_group_condition = "AND ( " . implode (' OR ', $sgrgroups) . " ) ";
        }

        $uncategorized_products_condition = VmConfig::get('show_uncat_child_products') ?
                '' : 'AND b.virtuemart_category_id > 0 ';

        $query = "
                SELECT DISTINCT
                    CONCAT( a.product_name, ' (', p.product_sku, ')' ) AS title,
                    a.virtuemart_product_id,
                    a.product_s_desc AS text,
                    p.created_on as created,
                    '2' AS browsernav,
                    medias.file_url_thumb as image,
                    GROUP_CONCAT(DISTINCT b.$category_field
                        ORDER BY b.$category_field SEPARATOR ', ') as section,
                    (SELECT pc2.virtuemart_category_id
                        FROM #__virtuemart_product_categories as pc2
                        WHERE pc2.virtuemart_product_id = a.virtuemart_product_id LIMIT 1) AS cat_id
                FROM `#__virtuemart_products_" . VMLANG . "` AS a
                JOIN #__virtuemart_products AS p USING (`virtuemart_product_id`)
                LEFT JOIN `#__virtuemart_product_categories` AS xref
                        ON xref.`virtuemart_product_id` = a.`virtuemart_product_id`
                LEFT JOIN `#__virtuemart_categories_" . VMLANG . "` AS b
                        ON b.`virtuemart_category_id` = xref.`virtuemart_category_id`
                LEFT JOIN `#__virtuemart_product_shoppergroups` as `psgr`
                        ON (`psgr`.`virtuemart_product_id`=`a`.`virtuemart_product_id`)
                LEFT JOIN `#__virtuemart_product_medias` AS pr_md
                        ON pr_md.`virtuemart_product_id` = a.`virtuemart_product_id`
                LEFT JOIN `#__virtuemart_medias` AS medias
                        ON pr_md.`virtuemart_media_id` = medias.`virtuemart_media_id`
                LEFT JOIN `#__virtuemart_product_customfields` AS cf
                        ON cf.virtuemart_product_id = a.virtuemart_product_id
                LEFT JOIN `#__virtuemart_customs` AS customs
                        ON customs.virtuemart_custom_id = cf.virtuemart_customfield_id
                WHERE
                        $where
                        AND p.published=1
                        $shopper_group_condition
                        $uncategorized_products_condition
                        AND pr_md.ordering=1  # Only select the first image
                GROUP BY xref.virtuemart_product_id
                ORDER BY $order";
        $db->setQuery($query, 0, $limit);

        $rows = $db->loadObjectList();
        if ($rows) {
            foreach ($rows as $key => $row) {
                $rows[$key]->href = 'index.php?option=com_virtuemart&view=productdetails&virtuemart_product_id=' .
                    $row->virtuemart_product_id . '&virtuemart_category_id=' . $row->cat_id;
                if (!empty($rows[$key]->image))
                    $rows[$key]->image =  JURI::root() . $rows[$key]->image;
            }
        }
        return $rows;
    }
}
