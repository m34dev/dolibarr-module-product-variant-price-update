<?php
/* Copyright (C) 2026		William Mead			<william@m34d.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file        htdocs/productvariantpriceupdate/class/productvariantpriceupdate.class.php
 * \ingroup     productvariantpriceupdate
 * \brief       Business logic for ProductVariantPriceUpdate module
 */

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

/**
 * Class for ProductVariantPriceUpdate
 */
class ProductVariantPriceUpdate
{
	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * Handles each row of a variant import profile, dispatching to the appropriate handler
	 * based on the import code. Returns 1 to replace standard import_insert() (which cannot
	 * handle llx_product_attribute_combination due to the missing import_key column).
	 *
	 * @param	array		$parameters		Hook metadatas: 'datatoimport', 'arrayrecord', 'array_match_file_to_database'
	 * @param	mixed		&$object		Not used
	 * @param	string		&$action		Not used
	 * @param	HookManager	$hookmanager	Hook manager
	 * @return	int							< 0 on error, 0 on success (standard code runs), 1 to replace standard code
	 */
	public function ImportInsert($parameters, &$object, &$action, $hookmanager): int
	{
		if ($parameters['datatoimport'] === 'productvariantpriceupdate_variantprices') {
			return $this->importUpdateVariantPrices($parameters);
		}
		if ($parameters['datatoimport'] === 'productvariantpriceupdate_newvariants') {
			return $this->importCreateVariant($parameters);
		}
		return 0;
	}

	/**
	 * Handles a row of the variant price update import: updates variation_price and
	 * variation_weight on an existing combination then re-applies prices to the child.
	 *
	 * @param	array	$parameters		Hook parameters including 'arrayrecord', 'array_match_file_to_database'
	 * @return	int						< 0 on error, 1 to replace standard code
	 */
	private function importUpdateVariantPrices(array $parameters): int
	{
		global $db, $langs, $user;

		$langs->load("productvariantpriceupdate@productvariantpriceupdate");

		// Build a dbfield => value map from the column mapping and raw CSV record.
		// array_match_file_to_database keys are 1-based; arrayrecord is 0-based.
		$colValues = array();
		foreach ($parameters['array_match_file_to_database'] as $colkey => $dbfield) {
			$colValues[$dbfield] = $parameters['arrayrecord'][(int) $colkey]['val'] ?? null;
		}

		$childRef           = $colValues['pac.fk_product_child'] ?? null;
		$variationPrice     = $colValues['pac.variation_price'] ?? null;
		$variationIsPercent = $colValues['pac.variation_price_percentage'] ?? null;
		$variationWeight    = $colValues['pac.variation_weight'] ?? null;

		if (empty($childRef)) {
			$this->error = $langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("ChildProductRef"));
			return -1;
		}
		if ($variationPrice === null) {
			$this->error = $langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("VariationPrice"));
			return -1;
		}
		if ($variationIsPercent === null) {
			$this->error = $langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("VariationPriceIsPercent"));
			return -1;
		}

		require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductCombination.class.php';
		require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

		$child = new Product($db);
		if ($child->fetch(0, $childRef) <= 0) {
			dol_syslog(__METHOD__.' Could not fetch child product ref='.$childRef, LOG_ERR);
			$this->error = $langs->trans("ErrorProductNotFound", $childRef);
			return -1;
		}

		$comb = new ProductCombination($db);
		if ($comb->fetchByFkProductChild($child->id) <= 0) {
			dol_syslog(__METHOD__.' No combination found for child product id='.$child->id.' ref='.$childRef, LOG_ERR);
			$this->error = $langs->trans("ErrorNotACombinationProduct", $childRef);
			return -1;
		}

		$comb->variation_price            = (float) $variationPrice;
		$comb->variation_price_percentage = (int) $variationIsPercent;
		if ($variationWeight !== null) {
			$comb->variation_weight = (float) $variationWeight;
		}

		$sql  = 'UPDATE '.MAIN_DB_PREFIX.'product_attribute_combination SET';
		$sql .= ' variation_price = '.((float) $comb->variation_price);
		$sql .= ', variation_price_percentage = '.((int) $comb->variation_price_percentage);
		if ($variationWeight !== null) {
			$sql .= ', variation_weight = '.((float) $comb->variation_weight);
		}
		$sql .= ' WHERE fk_product_child = '.((int) $child->id);
		$sql .= ' AND entity IN ('.getEntity('product').')';

		if (!$db->query($sql)) {
			dol_syslog(__METHOD__.' SQL error updating combination for child ref='.$childRef.': '.$db->lasterror(), LOG_ERR);
			$this->error = $db->lasterror();
			return -1;
		}

		$parent = new Product($db);
		if ($parent->fetch($comb->fk_product_parent) <= 0) {
			dol_syslog(__METHOD__.' Could not fetch parent product id='.$comb->fk_product_parent.' for child ref='.$childRef, LOG_ERR);
			$this->error = $langs->trans("ErrorProductParentNotFound", $childRef);
			return -1;
		}

		$result = $comb->updateProperties($parent, $user);
		if ($result < 0) {
			dol_syslog(__METHOD__.' updateProperties failed for child ref='.$childRef.': '.$comb->error, LOG_ERR);
			$this->error = $comb->error;
			return -1;
		}

		// Increment the driver's update counter directly on the object — this is what
		// the simulation summary displays. Using $parameters['obj'] (an object handle)
		// is reliable across by-value array copies, unlike scalar references.
		$parameters['obj']->nbupdate++;

		return 1;
	}

	/**
	 * Handles a row of the new variant creation import: looks up the parent product,
	 * attribute and attribute value by ref, then calls createProductCombination().
	 *
	 * @param	array	$parameters		Hook parameters including 'arrayrecord', 'array_match_file_to_database'
	 * @return	int						< 0 on error, 1 to replace standard code
	 */
	private function importCreateVariant(array $parameters): int
	{
		global $db, $langs, $user;

		$langs->load("productvariantpriceupdate@productvariantpriceupdate");

		$colValues = array();
		foreach ($parameters['array_match_file_to_database'] as $colkey => $dbfield) {
			$colValues[$dbfield] = $parameters['arrayrecord'][(int) $colkey]['val'] ?? null;
		}

		$parentRef          = $colValues['pac.fk_product_parent'] ?? null;
		$attrRef            = $colValues['pa.ref'] ?? null;
		$attrValueRef       = $colValues['pav.ref'] ?? null;
		$variantRef         = $colValues['pac.fk_product_child'] ?? null;
		$variationPrice     = $colValues['pac.variation_price'] ?? null;
		$variationIsPercent = $colValues['pac.variation_price_percentage'] ?? null;
		$variationWeight    = $colValues['pac.variation_weight'] ?? null;

		if (empty($parentRef)) {
			$this->error = $langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("ParentProductRef"));
			return -1;
		}
		if (empty($attrRef)) {
			$this->error = $langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("VariantAttributeRef"));
			return -1;
		}
		if (empty($attrValueRef)) {
			$this->error = $langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("VariantAttributeValueRef"));
			return -1;
		}
		if ($variationPrice === null) {
			$this->error = $langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("VariationPrice"));
			return -1;
		}
		if ($variationIsPercent === null) {
			$this->error = $langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("VariationPriceIsPercent"));
			return -1;
		}

		require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductAttribute.class.php';
		require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductCombination.class.php';
		require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

		$parent = new Product($db);
		if ($parent->fetch(0, $parentRef) <= 0) {
			dol_syslog(__METHOD__.' Could not fetch parent product ref='.$parentRef, LOG_ERR);
			$this->error = $langs->trans("ErrorProductNotFound", $parentRef);
			return -1;
		}

		$attrId = 0;
		$attrObj = new ProductAttribute($db);
		foreach ($attrObj->fetchAll() as $attr) {
			if ($attr->ref === $attrRef) {
				$attrId = (int) $attr->id;
				break;
			}
		}
		if (!$attrId) {
			dol_syslog(__METHOD__.' Attribute not found ref='.$attrRef, LOG_ERR);
			$this->error = $langs->trans("ErrorAttributeNotFound", $attrRef);
			return -1;
		}

		require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductAttributeValue.class.php';

		$attrValueId = 0;
		$attrValueObj = new ProductAttributeValue($db);
		foreach ($attrValueObj->fetchAllByProductAttribute($attrId) as $attrValue) {
			if ($attrValue->ref === $attrValueRef) {
				$attrValueId = (int) $attrValue->id;
				break;
			}
		}
		if (!$attrValueId) {
			dol_syslog(__METHOD__.' Attribute value not found ref='.$attrValueRef.' for attribute id='.$attrId, LOG_ERR);
			$this->error = $langs->trans("ErrorAttributeValueNotFound", $attrValueRef, $attrRef);
			return -1;
		}

		$combinations = array($attrId => $attrValueId);
		$variations = array(
			$attrId => array(
				$attrValueId => array(
					'price'  => (float) $variationPrice,
					'weight' => $variationWeight !== null ? (float) $variationWeight : 0.0,
				),
			),
		);

		$comb = new ProductCombination($db);
		$result = $comb->createProductCombination(
			$user,
			$parent,
			$combinations,
			$variations,
			(bool) (int) $variationIsPercent,
			false,
			false,
			!empty($variantRef) ? $variantRef : false
		);

		if ($result < 0) {
			dol_syslog(__METHOD__.' createProductCombination failed for parent ref='.$parentRef.': '.$comb->error, LOG_ERR);
			$this->error = $comb->error;
			return -1;
		}

		$parameters['obj']->nbinsert++;

		return 1;
	}
}
