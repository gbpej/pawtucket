<?php
/** ---------------------------------------------------------------------
 * app/lib/ca/BundlableLabelableBaseModelWithAttributes.php : base class for models that take application of bundles
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2008-2012 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of 
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  
 *
 * This source code is free and modifiable under the terms of 
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * @package CollectiveAccess
 * @subpackage BaseModel
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License version 3
 *
 * ----------------------------------------------------------------------
 */
 
 /**
  *
  */

require_once(__CA_LIB_DIR__."/ca/IBundleProvider.php");
require_once(__CA_LIB_DIR__."/ca/LabelableBaseModelWithAttributes.php");
require_once(__CA_LIB_DIR__."/core/Plugins/SearchEngine/CachedResult.php");

require_once(__CA_LIB_DIR__."/ca/IDNumbering.php");
require_once(__CA_APP_DIR__."/helpers/accessHelpers.php");
require_once(__CA_APP_DIR__."/helpers/searchHelpers.php");

define('__CA_BUNDLE_ACCESS_NONE__', 0);
define('__CA_BUNDLE_ACCESS_READONLY__', 1);
define('__CA_BUNDLE_ACCESS_EDIT__', 2);

class BundlableLabelableBaseModelWithAttributes extends LabelableBaseModelWithAttributes implements IBundleProvider {
	# ------------------------------------------------------
	protected $BUNDLES = array(
		
	);
	
	protected $opo_idno_plugin_instance;
	
	static $s_idno_instance_cache = array();
	# ------------------------------------------------------
	public function __construct($pn_id=null) {
		require_once(__CA_MODELS_DIR__."/ca_editor_uis.php");
		parent::__construct($pn_id);	# call superclass constructor
		
		$this->initLabelDefinitions();
	}
	# ------------------------------------------------------
	/**
	 * Overrides load() to initialize bundle specifications
	 */
	public function load ($pm_id=null) {
		$vn_rc = parent::load($pm_id);
		$this->initLabelDefinitions();
		
		return $vn_rc;
	}
	# ------------------------------------------------------
	/**
	 * Override insert() to check type_id (or whatever the type key is called in the table as returned by getTypeFieldName())
	 * against the ca_lists list for the table (as defined by getTypeListCode())
	 */ 
	public function insert($pa_options=null) {
		$vb_we_set_transaction = false;
		
		if (!$this->inTransaction()) {
			$this->setTransaction(new Transaction($this->getDb()));
			$vb_we_set_transaction = true;
		}
		
		$this->opo_app_plugin_manager->hookBeforeBundleInsert(array('id' => null, 'table_num' => $this->tableNum(), 'table_name' => $this->tableName(), 'instance' => $this));
		
		$vb_web_set_change_log_unit_id = BaseModel::setChangeLogUnitID();
		
		// check that type_id is valid for this table
		$t_list = new ca_lists();
		$vn_type_id = $this->get($this->getTypeFieldName());
		$va_field_info = $this->getFieldInfo($this->getTypeFieldName());
		
		$vb_error = false;
		if ($this->getTypeFieldName() && !(!$vn_type_id && $va_field_info['IS_NULL'])) {
			if (!($vn_ret = $t_list->itemIsEnabled($this->getTypeListCode(), $vn_type_id))) {
				$va_type_list = $this->getTypeList(array('directChildrenOnly' => false, 'returnHierarchyLevels' => true, 'item_id' => null));
				if(is_null($vn_ret)) {
					$this->postError(2510, _t("<em>%1</em> is invalid", $va_type_list[$vn_type_id]['name_singular']), "BundlableLabelableBaseModelWithAttributes->insert()");
				} else {
					$this->postError(2510, _t("<em>%1</em> is not enabled", $va_type_list[$vn_type_id]['name_singular']), "BundlableLabelableBaseModelWithAttributes->insert()");
				}
				$vb_error = true;
			}
		
			if ($this->HIERARCHY_PARENT_ID_FLD && (bool)$this->getAppConfig()->get($this->tableName().'_enforce_strict_type_hierarchy')) {
				// strict means if it has a parent is can only have types that are direct sub-types of the parent's type
				// and if it is the root of the hierarchy it can only take a top-level type
				if ($vn_parent_id = $this->get($this->HIERARCHY_PARENT_ID_FLD)) {
					// is child
					$t_parent = $this->_DATAMODEL->getInstanceByTableName($this->tableName());
					if ($t_parent->load($vn_parent_id)) {
						$vn_parent_type_id = $t_parent->getTypeID();
						$va_type_list = $t_parent->getTypeList(array('directChildrenOnly' => ($this->getAppConfig()->get($this->tableName().'_enforce_strict_type_hierarchy') == '~') ? false : true, 'childrenOfCurrentTypeOnly' => true, 'returnHierarchyLevels' => true));

						if (!isset($va_type_list[$this->getTypeID()])) {
							$va_type_list = $this->getTypeList(array('directChildrenOnly' => false, 'returnHierarchyLevels' => true, 'item_id' => null));

							$this->postError(2510, _t("<em>%1</em> is not a valid type for a child record of type <em>%2</em>", $va_type_list[$this->getTypeID()]['name_singular'], $va_type_list[$vn_parent_type_id]['name_singular']), "BundlableLabelableBaseModelWithAttributes->insert()");
							$vb_error = true;
						}
					} else {
						// error - no parent?
						$this->postError(2510, _t("No parent was found when verifying type of new child"), "BundlableLabelableBaseModelWithAttributes->insert()");
						$vb_error = true;
					}
				} else {
					// is root
					$va_type_list = $this->getTypeList(array('directChildrenOnly' => true, 'item_id' => null));
					if (!isset($va_type_list[$this->getTypeID()])) {
						$va_type_list = $this->getTypeList(array('directChildrenOnly' => false, 'returnHierarchyLevels' => true, 'item_id' => null));
						
						$this->postError(2510, _t("<em>%1</em> is not a valid type for a top-level record", $va_type_list[$this->getTypeID()]['name_singular']), "BundlableLabelableBaseModelWithAttributes->insert()");
						$vb_error = true;
					}
				}
			}
		}
		
		if (!$this->_validateIncomingAdminIDNo(true, true)) { $vb_error =  true; }
		
		if ($vb_error) {			
			// push all attributes onto errored list
			$va_inserted_attributes_that_errored = array();
			foreach($this->opa_attributes_to_add as $va_info) {
				$va_inserted_attributes_that_errored[$va_info['element']][] = $va_info['values'];
			}
			foreach($va_inserted_attributes_that_errored as $vs_element => $va_list) {
				$this->setFailedAttributeInserts($vs_element, $va_list);
			}
			
			if ($vb_web_set_change_log_unit_id) { BaseModel::unsetChangeLogUnitID(); }
			if ($vb_we_set_transaction) { $this->removeTransaction(false); }
			$this->_FIELD_VALUES[$this->primaryKey()] = null;		// clear primary key set by BaseModel::insert()
			return false;
		}
		
		$this->_generateSortableIdentifierValue();
		
		// stash attributes to add
		$va_attributes_added = $this->opa_attributes_to_add;
		if (!($vn_rc = parent::insert($pa_options))) {	
			// push all attributes onto errored list
			$va_inserted_attributes_that_errored = array();
			foreach($va_attributes_added as $va_info) {
				if (isset($this->opa_failed_attribute_inserts[$va_info['element']])) { continue; }
				$va_inserted_attributes_that_errored[$va_info['element']][] = $va_info['values'];
			}
			foreach($va_inserted_attributes_that_errored as $vs_element => $va_list) {
				$this->setFailedAttributeInserts($vs_element, $va_list);
			}
			
			if ($vb_web_set_change_log_unit_id) { BaseModel::unsetChangeLogUnitID(); }
			if ($vb_we_set_transaction) { $this->removeTransaction(false); }
			$this->_FIELD_VALUES[$this->primaryKey()] = null;		// clear primary key set by BaseModel::insert()
			return false;
		}
		
		if ($vb_web_set_change_log_unit_id) { BaseModel::unsetChangeLogUnitID(); }
	
		$this->opo_app_plugin_manager->hookAfterBundleInsert(array('id' => $this->getPrimaryKey(), 'table_num' => $this->tableNum(), 'table_name' => $this->tableName(), 'instance' => $this));
		
		if ($vb_we_set_transaction) { $this->removeTransaction(true); }
		return $vn_rc;
	}
	# ------------------------------------------------------
	/**
	 * Override update() to generate sortable version of user-defined identifier field
	 */ 
	public function update($pa_options=null) {
		$vb_we_set_transaction = false;
		if (!$this->inTransaction()) {
			$this->setTransaction(new Transaction($this->getDb()));
			$vb_we_set_transaction = true;
		}
		
		$vb_web_set_change_log_unit_id = BaseModel::setChangeLogUnitID();
		
		$this->opo_app_plugin_manager->hookBeforeBundleUpdate(array('id' => $this->getPrimaryKey(), 'table_num' => $this->tableNum(), 'table_name' => $this->tableName(), 'instance' => $this));
		
		$va_errors = array();
		if (!$this->_validateIncomingAdminIDNo(true, false)) { 
			 $va_errors = $this->errors();
			 // don't save number if it's invalid
			 if ($vs_idno_field = $this->getProperty('ID_NUMBERING_ID_FIELD')) {
			 	$this->set($vs_idno_field, $this->getOriginalValue($vs_idno_field));
			 }
		} else {
			$this->_generateSortableIdentifierValue();
		}
	
		$vn_rc = parent::update($pa_options);
		$this->errors = array_merge($this->errors, $va_errors);
		
		$this->opo_app_plugin_manager->hookAfterBundleUpdate(array('id' => $this->getPrimaryKey(), 'table_num' => $this->tableNum(), 'table_name' => $this->tableName(), 'instance' => $this));
		
		if ($vb_web_set_change_log_unit_id) { BaseModel::unsetChangeLogUnitID(); }
		
		if ($vb_we_set_transaction) { $this->removeTransaction($vn_rc); }
		return $vn_rc;
	}	
	# ------------------------------------------------------------------
	/**
	 * Duplicates record, including labels, attributes and relationships. "Special" bundles - those
	 * specific to a model - should be duplicated by the model by overriding BundlableLabelablleBaseModelWithAttributes::duplicate()
	 * and doing any required work after BundlableLabelablleBaseModelWithAttributes::duplicate() has finished
	 * 
	 * @param array $pa_options
	 *		duplicate_nonpreferred_labels = if set nonpreferred labels will be duplicated. Default is false.
	 *		duplicate_attributes = if set all content fields (intrinsics and attributes) will be duplicated. Default is false.
	 *		duplicate_relationships = if set to an array of table names, all relationships to be specified tables will be duplicated. Default is null - no relationships duplicated.
	 *		user_id = User ID of the user to make owner of the newly duplicated record (for records that support ownership by a user like ca_bundle_displays)
	 *		
	 * @return BundlableLabelablleBaseModelWithAttributes instance of newly created duplicate item
	 */
	public function duplicate($pa_options=null) {
		if (!$this->getPrimaryKey()) { return false; }
		$vs_idno_fld = $this->getProperty('ID_NUMBERING_ID_FIELD');
		$vs_idno_sort_fld = $this->getProperty('ID_NUMBERING_SORT_FIELD');
		$vs_pk = $this->primaryKey();
		
		$vb_duplicate_nonpreferred_labels = isset($pa_options['duplicate_nonpreferred_labels']) && $pa_options['duplicate_nonpreferred_labels'];
		$vb_duplicate_attributes = isset($pa_options['duplicate_attributes']) && $pa_options['duplicate_attributes'];
		$va_duplicate_relationships = (isset($pa_options['duplicate_relationships']) && is_array($pa_options['duplicate_relationships']) && sizeof($pa_options['duplicate_relationships'])) ? $pa_options['duplicate_relationships'] : array();
		
		
		$vb_we_set_transaction = false;
		if (!$this->inTransaction()) {
			$this->setTransaction($o_t = new Transaction($this->getDb()));
			$vb_we_set_transaction = true;
		} else {
			$o_t = $this->getTransaction();
		}
		
		// create new instance
		if (!($t_dupe = $this->_DATAMODEL->getInstanceByTableName($this->tableName()))) { 
			if ($vb_we_set_transaction) { $this->removeTransaction(false);}
			return null;
		}
		$t_dupe->purify($this->purify());
		$t_dupe->setTransaction($o_t);
		
		// duplicate primary record + intrinsics
		$va_field_list = $this->getFormFields(true, true);
		foreach($va_field_list as $vn_i => $vs_field) {
			if (in_array($vs_field, array($vs_idno_fld, $vs_idno_sort_fld, $vs_pk))) { continue; }		// skip idno fields
			$t_dupe->set($vs_field, $this->get($this->tableName().'.'.$vs_field));
		}
		$t_dupe->set($this->getTypeFieldName(), $this->getTypeID());
		
		// Calculate identifier using numbering plugin
		if ($vs_idno_fld) {
			if (method_exists($this, "getIDNoPlugInInstance") && ($o_numbering_plugin = $this->getIDNoPlugInInstance())) {
				if (!($vs_sep = $o_numbering_plugin->getSeparator())) { $vs_sep = ''; }
				if (!is_array($va_idno_values = $o_numbering_plugin->htmlFormValuesAsArray($vs_idno_fld, $this->get($vs_idno_fld), false, false, true))) { $va_idno_values = array(); }

				$t_dupe->set($vs_idno_fld, join($vs_sep, $va_idno_values));	// true=always set serial values, even if they already have a value; this let's us use the original pattern while replacing the serial value every time through
			} 
			
			if (!($vs_idno_stub = trim($t_dupe->get($vs_idno_fld)))) {
				$vs_idno_stub = trim($this->get($vs_idno_fld));
			}
			if ($vs_idno_stub) {
				$t_lookup = $this->_DATAMODEL->getInstanceByTableName($this->tableName());
				$va_tmp = preg_split("![{$vs_sep}]+!", $vs_idno_stub);
				$vs_suffix = is_array($va_tmp) ? array_pop($va_tmp) : '';
				if (!is_numeric($vs_suffix)) { 
					$vs_suffix = 0; 
				} else {
					$vs_idno_stub = preg_replace("!{$vs_suffix}$!", '', $vs_idno_stub);	
				}
				do {
					$vs_suffix = (int)$vs_suffix + 1;
					$vs_idno = trim($vs_idno_stub).$vs_sep.trim($vs_suffix);
				} while($t_lookup->load(array($vs_idno_fld => $vs_idno)));
			} else {
				$vs_idno = "???";
			}
			if ($vs_idno == $this->get($vs_idno_fld)) { $vs_idno .= " ["._t('DUP')."]"; }
			$t_dupe->set($vs_idno_fld, $vs_idno);
		}
		
		$t_dupe->setMode(ACCESS_WRITE);
		
		if (isset($pa_options['user_id']) && $pa_options['user_id'] && $t_dupe->hasField('user_id')) { $t_dupe->set('user_id', $pa_options['user_id']); }
		$t_dupe->insert();
		
		if ($t_dupe->numErrors()) {
			$this->errors = $t_dupe->errors;
			$this->removeTransaction(false);
			if ($vb_we_set_transaction) { $this->removeTransaction(false);}
			return false;
		}
		
		// duplicate labels
		$va_labels = $this->getLabels();
		$vs_label_display_field = $t_dupe->getLabelDisplayField();
		foreach($va_labels as $vn_label_id => $va_labels_by_locale) {
			foreach($va_labels_by_locale as $vn_locale_id => $va_label_list) {
				foreach($va_label_list as $vn_i => $va_label_info) {
					unset($va_label_info['source_info']);
					if (!$vb_duplicate_nonpreferred_labels && !$va_label_info['is_preferred']) { continue; }
					$va_label_info[$vs_label_display_field] .= " ["._t('Duplicate')."]";
					$t_dupe->addLabel(
						$va_label_info, $va_label_info['locale_id'], $va_label_info['type_id'], $va_label_info['is_preferred']
					);
					if ($t_dupe->numErrors()) {
						$this->errors = $t_dupe->errors;
						if ($vb_we_set_transaction) { $this->removeTransaction(false);}
						return false;
					}
				}
			}
		}
		
		// duplicate attributes
		if ($vb_duplicate_attributes) {
			if (!$t_dupe->copyAttributesFrom($this->getPrimaryKey())) {
				if ($vb_we_set_transaction) { $this->removeTransaction(false);}
				return false;
			}
		}
		
		// duplicate relationships
		foreach(array(
			'ca_objects', 'ca_object_lots', 'ca_entities', 'ca_places', 'ca_occurrences', 'ca_collections', 'ca_list_items', 'ca_loans', 'ca_movements', 'ca_storage_locations', 'ca_tour_stops'
		) as $vs_rel_table) {
			if (!in_array($vs_rel_table, $va_duplicate_relationships)) { continue; }
			if ($this->copyRelationships($vs_rel_table, $t_dupe->getPrimaryKey()) === false) {
				$this->errors = $t_dupe->errors;
				if ($vb_we_set_transaction) { $this->removeTransaction(false);}
				return false;
			}
		}
		
		if ($vb_we_set_transaction) { $this->removeTransaction(true);}
		return $t_dupe;
	}	
	# ------------------------------------------------------
	/**
	 * Overrides set() to check that the type field is not being set improperly
	 */
	public function set($pa_fields, $pm_value="", $pa_options=null) {
		if (!is_array($pa_fields)) {
			$pa_fields = array($pa_fields => $pm_value);
		}
		
		if ($this->getPrimaryKey() && isset($pa_fields[$this->getTypeFieldName()]) && !defined('__CA_ALLOW_SETTING_OF_PRIMARY_KEYS__')) {
			$this->postError(2520, _t("Type id cannot be set after insert"), "BundlableLabelableBaseModelWithAttributes->set()");
			return false;
		}
		
		if (in_array($this->getProperty('ID_NUMBERING_ID_FIELD'), $pa_fields)) {
			if (!$this->_validateIncomingAdminIDNo(true, true)) { return false; }
		}
		
		return parent::set($pa_fields, "", $pa_options);
	}
	# ------------------------------------------------------
	/**
	 * Overrides get() to support bundleable-level fields (relationships)
	 *
	 * Options:
	 *		All supported by BaseModelWithAttributes::get() plus:
	 *		retrictToRelationshipTypes - array of ca_relationship_types.type_id values to filter related items on. *MUST BE INTEGER TYPE_IDs, NOT type_code's* This limitation is for performance reasons. You have to convert codes to integer type_id's before invoking get
	 *		sort = optional array of bundles to sort returned values on. Currently only supported when getting related values via simple related <table_name> and <table_name>.related invokations. Eg. from a ca_objects results you can use the 'sort' option got get('ca_entities'), get('ca_entities.related') or get('ca_objects.related'). The bundle specifiers are fields with or without tablename. Only those fields returned for the related tables (intrinsics and label fields) are sortable. You cannot sort on attributes.
	 */
	public function get($ps_field, $pa_options=null) {
		if(!is_array($pa_options)) { $pa_options = array(); }
		$vs_template = 				(isset($pa_options['template'])) ? $pa_options['template'] : null;
		$vb_return_as_array = 		(isset($pa_options['returnAsArray'])) ? (bool)$pa_options['returnAsArray'] : false;
		$vb_return_all_locales = 	(isset($pa_options['returnAllLocales'])) ? (bool)$pa_options['returnAllLocales'] : false;
		$vs_delimiter = 			(isset($pa_options['delimiter'])) ? $pa_options['delimiter'] : ' ';
		$va_restrict_to_rel_types = (isset($pa_options['restrictToRelationshipTypes']) && is_array($pa_options['restrictToRelationshipTypes'])) ? $pa_options['restrictToRelationshipTypes'] : false;
		if ($vb_return_all_locales && !$vb_return_as_array) { $vb_return_as_array = true; }
		
		$va_get_where = 			(isset($pa_options['where']) && is_array($pa_options['where']) && sizeof($pa_options['where'])) ? $pa_options['where'] : null;
		
		
		// does get refer to an attribute?
		$va_tmp = explode('.', $ps_field);
		
		if(sizeof($va_tmp) > 1) {
			if ($va_tmp[0] != $this->tableName()) {
				$vs_access_chk_key  = $ps_field;
			} else {
				$va_tmp2 = $va_tmp;
				array_shift($va_tmp2);
				$vs_access_chk_key  = join(".", $va_tmp2); 
			}
		} else {
			$vs_access_chk_key = $ps_field;
		}
		
		
		if (!$this->hasField($va_tmp[sizeof($va_tmp)-1]) || $this->getFieldInfo($va_tmp[sizeof($va_tmp)-1], 'ALLOW_BUNDLE_ACCESS_CHECK')) {
			if (caGetBundleAccessLevel($this->tableName(), $vs_access_chk_key) == __CA_BUNDLE_ACCESS_NONE__) {
				return null;
			}
		}
		
		switch(sizeof($va_tmp)) {
			# -------------------------------------
			case 1:		// table_name
				if ($t_instance = $this->_DATAMODEL->getInstanceByTableName($va_tmp[0], true)) {
					$va_related_items = $this->getRelatedItems($va_tmp[0], $pa_options);
					if (!is_array($va_related_items)) { return null; }
					
					if($vb_return_as_array) {
						 if ($vb_return_all_locales) {
						 	return $va_related_items;
						 } else {
						 	$va_proc_labels = array();
							foreach($va_related_items as $vn_relation_id => $va_relation_info) {
								$va_relation_info['labels'] = caExtractValuesByUserLocale(array(0 => $va_relation_info['labels']));	
								$va_related_items[$vn_relation_id]['labels'] = $va_relation_info['labels'];
							}
							return $va_related_items;
						 }
					} else {
						if ($vs_template) {							
							$va_template_opts = $pa_options;
							unset($va_template_opts['request']);
							unset($va_template_opts['template']);
							$va_template_opts['returnAsArray'] = true;
							
							$va_ids = array();
							$vs_pk = $t_instance->primaryKey();
							if (is_array($va_rel_items = $this->get($va_tmp[0], $va_template_opts))) {
								foreach($va_rel_items as $vn_rel_id => $va_rel_item) {
									$va_ids[] = $va_rel_item[$vs_pk];
								}
							} else {
								$va_rel_items = array();
							}
							return caProcessTemplateForIDs($vs_template, $va_tmp[0], $va_ids, array_merge($pa_options, array('relatedValues' => array_values($va_rel_items))));
						} else {
							$va_proc_labels = array();
							foreach($va_related_items as $vn_relation_id => $va_relation_info) {
								$va_proc_labels = array_merge($va_proc_labels, caExtractValuesByUserLocale(array($vn_relation_id => $va_relation_info['labels'])));
								
							}
							
							return join($vs_delimiter, $va_proc_labels);
						}
					}
				}
				break;
			# -------------------------------------
			case 2:		// table_name.field_name || table._name.related
			case 3:		// table_name.field_name.sub_element
			case 4:		// table_name.related.field_name.sub_element
				//
				// TODO: this code is compact, relatively simple and works but is slow since it
				// generates a lot more identical database queries than we'd like
				// We will need to add some notion of caching so that multiple calls to get() 
				// for various fields in the same list of related items don't cause repeated queries
				//
				$vb_is_related = false;
				if ($va_tmp[1] === 'related') {
					array_splice($va_tmp, 1, 1);
					$vb_is_related = true;
				}
				
				if ($vb_is_related || ($va_tmp[0] !== $this->tableName())) {		// must be related table			
					$t_instance = $this->_DATAMODEL->getInstanceByTableName($va_tmp[0], true);
					
					if ($vs_template) {
						$va_template_opts = $pa_options;
						unset($va_template_opts['request']);
						unset($va_template_opts['template']);
						$va_template_opts['returnAsArray'] = true;
						
						$va_ids = array();
						$vs_pk = $t_instance->primaryKey();
						if (is_array($va_rel_items = $this->get($va_tmp[0], $va_template_opts))) {
							foreach($va_rel_items as $vn_rel_id => $va_rel_item) {
								$va_ids[] = $va_rel_item[$vs_pk];
							}
						} else {
							$va_rel_items = array();
						}
						return caProcessTemplateForIDs($vs_template, $va_tmp[0], $va_ids, array_merge($pa_options, array('relatedValues' => array_values($va_rel_items))));
					}
					
					$va_related_items = $this->getRelatedItems($va_tmp[0], array_merge($pa_options, array('returnLabelsAsArray' => true)));
				
					if (is_array($va_restrict_to_rel_types) && sizeof($va_restrict_to_rel_types)) {
						require_once(__CA_MODELS_DIR__.'/ca_relationship_types.php');
						$t_rel_types = new ca_relationship_types();
						
						$va_restrict_to_rel_types = $t_rel_types->relationshipTypeListToIDs($t_rel_types->getRelationshipTypeTable($this->tableName(), $va_tmp[0]), $va_restrict_to_rel_types, array('includeChildren' => true));
					}
					
					$va_items = array();
					if(is_array($va_related_items) && (sizeof($va_related_items) > 0)) {
						foreach($va_related_items as $vn_rel_id => $va_related_item) {
							if (is_array($va_restrict_to_rel_types) && !in_array($va_related_item['relationship_type_id'], $va_restrict_to_rel_types)) { continue; }
							
							if ($va_tmp[1] == 'relationship_typename') {
								$va_items[] = $va_related_item['relationship_typename'];
								continue;
							}
							
							if ($va_tmp[1] == 'hierarchy') {
								if ($t_instance->load($va_related_item[$t_instance->primaryKey()])) {
									$va_items[] = $t_instance->get(join('.', $va_tmp), $pa_options);
								}
								continue;
							}
							
							// is field directly returned by getRelatedItems()?
							if (isset($va_tmp[1]) && isset($va_related_item[$va_tmp[1]]) && $t_instance->hasField($va_tmp[1])) {
								if ($vb_return_as_array) {
									if ($vb_return_all_locales) {
										// for return as locale-index array
										$va_items[$va_related_item['relation_id']][$va_related_item['locale_id']][] = $va_related_item[$va_tmp[1]];
									} else {
										// for return as simple array
										$va_items[] = $va_related_item[$va_tmp[1]];
									}
								} else {
									// for return as string
									$va_items[] = $va_related_item[$va_tmp[1]];
								}
								continue;
							}
							
							// is field preferred labels?
							if ($va_tmp[1] === 'preferred_labels') {
								if (!isset($va_tmp[2])) {
									if ($vb_return_as_array) {
										if ($vb_return_all_locales) {
											// for return as locale-index array
											$va_items[$va_related_item['relation_id']][] = $va_related_item['labels'];
										} else {
											// for return as simple array
											$va_item_list = caExtractValuesByUserLocale(array($va_related_item['labels']));
											foreach($va_item_list as $vn_x => $va_item) {
												$va_items[] = $va_item[$t_instance->getLabelDisplayField()];
											}
										}
									} else {
										// for return as string
										$va_items[] = $va_related_item['label'][$t_instance->getLabelDisplayField()];
									}
								} else {
									if ($vb_return_as_array && $vb_return_all_locales) {
										// for return as locale-index array
										foreach($va_related_item['labels'] as $vn_locale_id => $va_label) {
											$va_items[$va_related_item['relation_id']][$vn_locale_id][] = $va_label[$va_tmp[2]];
										}
									} else {
										foreach(caExtractValuesByUserLocale(array($va_related_item['labels'])) as $vn_i => $va_label) {
											// for return as string or simple array
											$va_items[] = $va_label[$va_tmp[2]];
										}
									}
								}
								
								continue;
							}
							
							// TODO: add support for nonpreferred labels
							
							if ($t_instance->load($va_related_item[$t_instance->primaryKey()])) {
								if (isset($va_tmp[1])) {
									if ($vm_val = $t_instance->get($va_tmp[1], $pa_options)) {
										if ($vb_return_as_array) {
											if ($vb_return_all_locales) {
												// for return as locale-index array
												$va_items = $vm_val;
											} else {
												// for return as simple array
												$va_items = array_merge($va_items, $vm_val);
											}
										} else {
											// for return as string
											$va_items[] = $vm_val;
										}	
										continue;
									} 
								} else {
									$va_items[]  = $this->get($va_tmp[0], $pa_options);
								}
							}
						}
					}
					
					if($vb_return_as_array) {
						return $va_items;
					} else {
						return join($vs_delimiter, $va_items);
					}
				}
				break;
			# -------------------------------------
		}
		
			
		return parent::get($ps_field, $pa_options);
	}
	# ------------------------------------------------------
	/**
	 *
	 */
	private function _validateIncomingAdminIDNo($pb_post_errors=true, $pb_dont_validate_idnos_for_parents_in_mono_hierarchies=false) {
	
		// we should not bother to validate
		$vn_hier_type = $this->getHierarchyType();
		if ($pb_dont_validate_idnos_for_parents_in_mono_hierarchies && in_array($vn_hier_type, array(__CA_HIER_TYPE_SIMPLE_MONO__, __CA_HIER_TYPE_MULTI_MONO__)) && ($this->get('parent_id') == null)) { return true; }
		
		if ($vs_idno_field = $this->getProperty('ID_NUMBERING_ID_FIELD')) {
			$va_idno_errors = $this->validateAdminIDNo($this->get($vs_idno_field));
			if (sizeof($va_idno_errors) > 0) {
				if ($pb_post_errors) {
					foreach($va_idno_errors as $vs_e) {
						$this->postError(1100, $vs_e, "BundlableLabelableBaseModelWithAttributes->insert()");
					}
				}
				return false;
			}
		}
		return true;
	}
	# ------------------------------------------------------------------
	/**
	  *
	  */
	public function getValuesForExport($pa_options=null) {
		$va_data = parent::getValuesForExport($pa_options);		// get intrinsics, attributes and labels
		
		$t_locale = new ca_locales();
		$t_list = new ca_lists();
		
		// get related items
		foreach(array('ca_objects', 'ca_entities', 'ca_places', 'ca_occurrences', 'ca_collections', 'ca_storage_locations',  'ca_loans', 'ca_movements', 'ca_tours', 'ca_tour_stops',  'ca_list_items') as $vs_table) {
			$va_related_items = $this->getRelatedItems($vs_table, array('returnAsArray' => true, 'returnAllLocales' => true));
			if(is_array($va_related_items) && sizeof($va_related_items)) {
				$va_related_for_export = array();
				$vn_i = 0;
				foreach($va_related_items as $vn_id => $va_related_item) {
					$va_related_for_export['related_'.$vn_i] = $va_related_item;
					$vn_i++;
				}
				
				$va_data['related_'.$vs_table] = $va_related_for_export;
			}
		}
		
		
		return $va_data;
	}
	# ----------------------------------------
	/**
	 *
	 */
	public function checkForDupeAdminIdnos($ps_idno=null, $pb_dont_remove_self=false) {
		if ($vs_idno_field = $this->getProperty('ID_NUMBERING_ID_FIELD')) {
			$o_db = $this->getDb();
			
			if (!$ps_idno) { $ps_idno = $this->get($vs_idno_field); }
			
			$vs_remove_self_sql = '';
			if (!$pb_dont_remove_self) {
				$vs_remove_self_sql = ' AND ('.$this->primaryKey().' <> '.intval($this->getPrimaryKey()).')';
			}
			
			$vs_idno_context_sql = '';
			if ($vs_idno_context_field = $this->getProperty('ID_NUMBERING_CONTEXT_FIELD')) {
				if ($vn_context_id = $this->get($vs_idno_context_field)) {
					$vs_idno_context_sql = ' AND ('.$vs_idno_context_field.' = '.$this->quote($vs_idno_context_field, $vn_context_id).')';
				} else {
					if ($this->getFieldInfo($vs_idno_context_field, 'IS_NULL')) {
						$vs_idno_context_sql = ' AND ('.$vs_idno_context_field.' IS NULL)';
					}
				}
			}
			
			$vs_deleted_sql = '';
			if ($this->hasField('deleted')) {
				$vs_deleted_sql = " AND (".$this->tableName().".deleted = 0)";
			}
			
			$qr_idno = $o_db->query("
				SELECT ".$this->primaryKey()." 
				FROM ".$this->tableName()." 
				WHERE {$vs_idno_field} = ? {$vs_remove_self_sql} {$vs_idno_context_sql} {$vs_deleted_sql}
			", $ps_idno);
			
			$va_ids = array();
			while($qr_idno->nextRow()) {
				$va_ids[] = $qr_idno->get($this->primaryKey());
			}
			return $va_ids;
		} 
		
		return array();
	}
	# ------------------------------------------------------
	/**
	 *
	 */
	private function _generateSortableIdentifierValue() {
		if (($vs_idno_field = $this->getProperty('ID_NUMBERING_ID_FIELD')) && ($vs_idno_sort_field = $this->getProperty('ID_NUMBERING_SORT_FIELD'))) {
			
			if (($o_idno = $this->getIDNoPlugInInstance()) && (method_exists($o_idno, 'getSortableValue'))) {	// try to use plug-in's sort key generator if defined
				$this->set($vs_idno_sort_field, $o_idno->getSortableValue($this->get($vs_idno_field)));
				return;
			}
			
			// Create reasonable facsimile of sortable value since 
			// idno plugin won't do it for us
			$va_tmp = preg_split('![^A-Za-z0-9]+!',  $this->get($vs_idno_field));
			
			$va_output = array();
			$va_zeroless_output = array();
			foreach($va_tmp as $vs_piece) {
				if (preg_match('!^([\d]+)!', $vs_piece, $va_matches)) {
					$vs_piece = $va_matches[1];
				}
				$vn_pad_len = 12 - mb_strlen($vs_piece);
				
				if ($vn_pad_len >= 0) {
					if (is_numeric($vs_piece)) {
						$va_output[] = str_repeat(' ', $vn_pad_len).$va_matches[1];
					} else {
						$va_output[] = $vs_piece.str_repeat(' ', $vn_pad_len);
					}
				} else {
					$va_output[] = $vs_piece;
				}
				if ($vs_tmp = preg_replace('!^[0]+!', '', $vs_piece)) {
					$va_zeroless_output[] = $vs_tmp;
				} else {
					$va_zeroless_output[] = $vs_piece;
				}
			}
		
			$this->set($vs_idno_sort_field, join('', $va_output).' '.join('.', $va_zeroless_output));
		}
		
		return;
	}
	# ------------------------------------------------------
	/**
	 * Check if a record already exists with the specified label
	 */
	 public function checkForDupeLabel($pn_locale_id, $pa_label_values) {
	 	$o_db = $this->getDb();
	 	$t_label = $this->getLabelTableInstance();
	 	
	 	unset($pa_label_values['displayname']);
	 	$va_sql = array();
	 	foreach($pa_label_values as $vs_field => $vs_value) {
	 		$va_sql[] = "(l.{$vs_field} = ?)";
	 	}
	 	
	 	if ($t_label->hasField('is_preferred')) { $va_sql[] = "(l.is_preferred = 1)"; }
	 	if ($t_label->hasField('locale_id')) { $va_sql[] = "(l.locale_id = ?)"; }
	 	if ($this->hasField('deleted')) { $va_sql[] = "(t.deleted = 0)"; }
	 	$va_sql[] = "(l.".$this->primaryKey()." <> ?)";
	 	
	 	$vs_sql = "SELECT ".$t_label->primaryKey()."
	 	FROM ".$t_label->tableName()." l
	 	INNER JOIN ".$this->tableName()." AS t ON t.".$this->primaryKey()." = l.".$this->primaryKey()."
	 	WHERE ".join(' AND ', $va_sql);
	
	 	$va_values = array_values($pa_label_values);
	 	$va_values[] = (int)$pn_locale_id;
	 	$va_values[] = (int)$this->getPrimaryKey();
	 	$qr_res = $o_db->query($vs_sql, $va_values);
	 	
	 	if ($qr_res->numRows() > 0) {
	 		return true;
	 	}
	 
	 	return false;
	 }
	# ------------------------------------------------------
	/**
	 *
	 */
	public function reloadLabelDefinitions() {
		$this->initLabelDefinitions();
	}
	# ------------------------------------------------------
	/**
	 *
	 */
	protected function initLabelDefinitions() {
		$this->BUNDLES = array(
			'preferred_labels' 			=> array('type' => 'preferred_label', 'repeating' => true, 'label' => _t("Preferred labels")),
			'nonpreferred_labels' 		=> array('type' => 'nonpreferred_label', 'repeating' => true,  'label' => _t("Non-preferred labels")),
		);
		
		// add form fields to bundle list
		foreach($this->getFormFields() as $vs_f => $va_info) {
			$vs_type_id_fld = isset($this->ATTRIBUTE_TYPE_ID_FLD) ? $this->ATTRIBUTE_TYPE_ID_FLD : null;
			if ($vs_f === $vs_type_id_fld) { continue; } 	// don't allow type_id field to be a bundle (isn't settable in a form)
			if (isset($va_info['DONT_USE_AS_BUNDLE']) && $va_info['DONT_USE_AS_BUNDLE']) { continue; }
			$this->BUNDLES[$vs_f] = array(
				'type' => 'intrinsic', 'repeating' => false, 'label' => $this->getFieldInfo($vs_f, 'LABEL')
			);
		}
		
		$vn_type_id = $this->getTypeID();
		
		// Create instance of idno numbering plugin (if table supports it)
		if ($vs_field = $this->getProperty('ID_NUMBERING_ID_FIELD')) {
			if (!$vn_type_id) { $vn_type_id = null; }
			if (!isset(BundlableLabelableBaseModelWithAttributes::$s_idno_instance_cache[$this->tableNum()."/".$vn_type_id])) {
				$va_types = array();
				$o_db = $this->getDb();		// have to do direct query here... if we use ca_list_items model we'll just endlessly recurse
				
				if ($vn_type_id) {
					
					$qr_res = $o_db->query("
						SELECT idno, list_id, hier_left, hier_right 
						FROM ca_list_items 
						WHERE 
							item_id = ?"
						, (int)$vn_type_id);
						
					if ($qr_res->nextRow()) {
						if ($vn_list_id = $qr_res->get('list_id')) {
							$vn_hier_left 		= $qr_res->get('hier_left');
							$vn_hier_right 		= $qr_res->get('hier_right');
							$vs_idno 			= $qr_res->get('idno');
							$qr_res = $o_db->query("
								SELECT idno, parent_id
								FROM ca_list_items 
								WHERE 
									(list_id = ? AND hier_left < ? AND hier_right > ?)", 
							(int)$vn_list_id, (int)$vn_hier_left, (int)$vn_hier_right);
							
							while($qr_res->nextRow()) {
								if (!$qr_res->get('parent_id')) { continue; }
								$va_types[] = $qr_res->get('idno');
							}
							$va_types[] = $vs_idno;
							$va_types = array_reverse($va_types);
						}
					}
				}
				BundlableLabelableBaseModelWithAttributes::$s_idno_instance_cache[$this->tableNum()."/".$vn_type_id] = $this->opo_idno_plugin_instance = IDNumbering::newIDNumberer($this->tableName(), $va_types, null, $o_db);
			} else {
				$this->opo_idno_plugin_instance = BundlableLabelableBaseModelWithAttributes::$s_idno_instance_cache[$this->tableNum()."/".$vn_type_id];
			}
		} else {
			$this->opo_idno_plugin_instance = null;
		}
		
		// add metadata elements
		foreach($this->getApplicableElementCodes($vn_type_id, false, false) as $vs_code) {
			$this->BUNDLES['ca_attribute_'.$vs_code] = array(
				'type' => 'attribute', 'repeating' => false, 'label' => $vs_code //$this->getAttributeLabel($vs_code)
			);
		}
	}
 	# ------------------------------------------------------
 	/**
 	 * Check if currently loaded row is save-able
 	 *
 	 * @param RequestHTTP $po_request
 	 * @return bool True if record can be saved, false if not
 	 */
 	public function isSaveable($po_request) {
 		// Check type restrictions
 		if ((bool)$this->getAppConfig()->get('perform_type_access_checking')) {
			$vn_type_access = $po_request->user->getTypeAccessLevel($this->tableName(), $this->getTypeID());
			if ($vn_type_access != __CA_BUNDLE_ACCESS_EDIT__) {
				return false;
			}
		}
		
 		// Check actions
 		if (!$this->getPrimaryKey() && !$po_request->user->canDoAction('can_create_'.$this->tableName())) {
 			return false;
 		}
 		if ($this->getPrimaryKey() && !$po_request->user->canDoAction('can_edit_'.$this->tableName())) {
 			return false;
 		}
 		
 		return true;
 	}
 	# ------------------------------------------------------
 	/**
 	 * Check if currently loaded row is deletable
 	 */
 	public function isDeletable($po_request) {
 		// Is row loaded?
 		if (!$this->getPrimaryKey()) { return false; }
 		
 		// Check type restrictions
 		if ((bool)$this->getAppConfig()->get('perform_type_access_checking')) {
			$vn_type_access = $po_request->user->getTypeAccessLevel($this->tableName(), $this->getTypeID());
			if ($vn_type_access != __CA_BUNDLE_ACCESS_EDIT__) {
				return false;
			}
		}
		
 		// Check actions
 		if (!$this->getPrimaryKey() && !$po_request->user->canDoAction('can_delete_'.$this->tableName())) {
 			return false;
 		}
 		
 		return true;
 	}
	# ------------------------------------------------------
	/**
	 * @param string $ps_bundle_name
	 * @param string $ps_placement_code
	 * @param array $pa_bundle_settings
	 * @param array $pa_options Supported options are:
	 *		config
	 *		viewPath
	 *		graphicsPath
	 */
	public function getBundleFormHTML($ps_bundle_name, $ps_placement_code, $pa_bundle_settings, $pa_options) {
		global $g_ui_locale;
		
		// Check if user has access to this bundle
		if ($pa_options['request']->user->getBundleAccessLevel($this->tableName(), $ps_bundle_name) == __CA_BUNDLE_ACCESS_NONE__) {
			return;
		}
		
		// Check if user has access to this type
		if ((bool)$this->getAppConfig()->get('perform_type_access_checking')) {
			$vn_type_access = $pa_options['request']->user->getTypeAccessLevel($this->tableName(), $this->getTypeID());
			if ($vn_type_access == __CA_BUNDLE_ACCESS_NONE__) {
				return;
			}
			if ($vn_type_access == __CA_BUNDLE_ACCESS_READONLY__) {
				$pa_bundle_settings['readonly'] = true;
			}
		}
		
		$va_info = $this->getBundleInfo($ps_bundle_name);
		if (!($vs_type = $va_info['type'])) { return null; }
		
		
		if (isset($pa_options['config']) && is_object($pa_options['config'])) {
			$o_config = $pa_options['config'];
		} else {
			$o_config = $this->getAppConfig();
		}
		
		if (!($vs_required_marker = $o_config->get('required_field_marker'))) {
			$vs_required_marker = '['._t('REQUIRED').']';
		}
		
		$vs_label = $vs_label_text = null;
		
		// is label for this bundle forced in bundle settings?
		if (isset($pa_bundle_settings['label']) && isset($pa_bundle_settings['label'][$g_ui_locale]) && ($pa_bundle_settings['label'][$g_ui_locale])) {
			$vs_label = $vs_label_text = $pa_bundle_settings['label'][$g_ui_locale];
		}
		
		$vs_element = '';
		$va_errors = array();
		switch($vs_type) {
			# -------------------------------------------------
			case 'preferred_label':
			case 'nonpreferred_label':
				if (is_array($va_error_objects = $pa_options['request']->getActionErrors($ps_bundle_name)) && sizeof($va_error_objects)) {
					$vs_display_format = $o_config->get('bundle_element_error_display_format');
					foreach($va_error_objects as $o_e) {
						$va_errors[] = $o_e->getErrorDescription();
					}
				} else {
					$vs_display_format = $o_config->get('bundle_element_display_format');
				}
				
				$pa_options['dontCache'] = true;	// we *don't* want to cache labels here
				$vs_element = ($vs_type === 'preferred_label') ? $this->getPreferredLabelHTMLFormBundle($pa_options['request'], $pa_options['formName'], $ps_placement_code, $pa_bundle_settings, $pa_options) : $this->getNonPreferredLabelHTMLFormBundle($pa_options['request'], $pa_options['formName'], $ps_placement_code, $pa_bundle_settings, $pa_options);
			
				if (!$vs_label_text) {  $vs_label_text = $va_info['label']; } 
				$vs_label = '<span class="formLabelText" id="'.$pa_options['formName'].'_'.$ps_placement_code.'">'.$vs_label_text.'</span>'; 
				
				if (($vs_type == 'preferred_label') && $o_config->get('show_required_field_marker') && $o_config->get('require_preferred_label_for_'.$this->tableName())) {
					$vs_label .= ' '.$vs_required_marker;
				}
				
				$vs_description = isset($pa_bundle_settings['description'][$g_ui_locale]) ? $pa_bundle_settings['description'][$g_ui_locale] : null;
				
				if (($vs_label_text) && ($vs_description)) {
					TooltipManager::add('#'.$pa_options['formName'].'_'.$ps_placement_code, "<h3>{$vs_label}</h3>{$vs_description}");
				}
				break;
			# -------------------------------------------------
			case 'intrinsic':
				if (isset($pa_bundle_settings['label'][$g_ui_locale]) && $pa_bundle_settings['label'][$g_ui_locale]) {
					$pa_options['label'] = $pa_bundle_settings['label'][$g_ui_locale];
				}
				if (!$pa_options['label']) {
					$pa_options['label'] = $this->getFieldInfo($ps_bundle_name, 'LABEL');
				}
				
				$vs_view_path = (isset($pa_options['viewPath']) && $pa_options['viewPath']) ? $pa_options['viewPath'] : $pa_options['request']->getViewsDirectoryPath();
				$o_view = new View($pa_options['request'], "{$vs_view_path}/bundles/");
			
					
				$va_lookup_url_info = caJSONLookupServiceUrl($pa_options['request'], $this->tableName());
				
				if ($this->getFieldInfo($ps_bundle_name, 'IDENTITY')) {
					$o_view->setVar('form_element', ($vn_id = (int)$this->get($ps_bundle_name)) ? $vn_id : "&lt;"._t('Not yet issued')."&gt;");
				} else {
					$vb_read_only = ($pa_bundle_settings['readonly'] || ($pa_options['request']->user->getBundleAccessLevel($this->tableName(), $ps_bundle_name) == __CA_BUNDLE_ACCESS_READONLY__)) ? true : false;
					
					$o_view->setVar('form_element', $this->htmlFormElement($ps_bundle_name, ($this->getProperty('ID_NUMBERING_ID_FIELD') == $ps_bundle_name) ? $o_config->get('idno_element_display_format_without_label') : $o_config->get('bundle_element_display_format_without_label'), 
						array_merge(
							array(	
								'readonly' 					=> $vb_read_only,						
								'error_icon' 				=> $pa_options['request']->getThemeUrlPath()."/graphics/icons/warning_small.gif",
								'progress_indicator'		=> $pa_options['request']->getThemeUrlPath()."/graphics/icons/indicator.gif",
								'lookup_url' 				=> $va_lookup_url_info['intrinsic']
							),
							$pa_options
						)
					));
				}
				$o_view->setVar('errors', $pa_options['request']->getActionErrors($ps_bundle_name));
				if (method_exists($this, "getDefaultMediaPreviewVersion")) {
					$o_view->setVar('display_media', $this->getMediaTag($ps_bundle_name, $this->getDefaultMediaPreviewVersion($ps_bundle_name)));
				}
				
				$vs_field_id = 'ca_intrinsic_'.$pa_options['formName'].'_'.$ps_placement_code;
				$vs_label = '<span class="formLabelText" id="'.$vs_field_id.'">'.$pa_options['label'].'</span>'; 
				
				if ($o_config->get('show_required_field_marker')) {
					if (($this->getFieldInfo($ps_bundle_name, 'FIELD_TYPE') == FT_TEXT) && is_array($va_bounds =$this->getFieldInfo($ps_bundle_name, 'BOUNDS_LENGTH')) && ($va_bounds[0] > 0)) {
						$vs_label .= ' '.$vs_required_marker;
					} else {
						if ((in_array($this->getFieldInfo($ps_bundle_name, 'FIELD_TYPE'), array(FT_NUMBER, FT_HISTORIC_DATERANGE, FT_DATERANGE)) && !$this->getFieldInfo($ps_bundle_name, 'IS_NULL'))) {
							$vs_label .= ' '.$vs_required_marker;
						}
					}
				}
				
				$o_view->setVar('t_instance', $this);
				$vs_element = $o_view->render('intrinsic.php', true);
				
				
				$vs_description =  (isset($pa_bundle_settings['description'][$g_ui_locale]) && $pa_bundle_settings['description'][$g_ui_locale]) ? $pa_bundle_settings['description'][$g_ui_locale]  : $this->getFieldInfo($ps_bundle_name, 'DESCRIPTION');
				if (($pa_options['label']) && ($vs_description)) {
					TooltipManager::add('#'.$vs_field_id, "<h3>".$pa_options['label']."</h3>{$vs_description}");
				}
				
				$vs_display_format = $o_config->get('bundle_element_display_format');
				break;
			# -------------------------------------------------
			case 'attribute':
				// bundle names for attributes are simply element codes prefixed with 'ca_attribute_'
				// since getAttributeHTMLFormBundle() takes a straight element code we have to strip the prefix here
				$vs_attr_element_code = str_replace('ca_attribute_', '', $ps_bundle_name);
				
				//if (is_array($va_error_objects = $pa_options['request']->getActionErrors($ps_bundle_name))) {
				//	$vs_display_format = $o_config->get('form_element_error_display_format');
				//	foreach($va_error_objects as $o_e) {
				//		$va_errors[] = $o_e->getErrorDescription();
				//	}
				//} else {
					$vs_display_format = $o_config->get('bundle_element_display_format');
				//}
				$vs_element = $this->getAttributeHTMLFormBundle($pa_options['request'], $pa_options['formName'], $vs_attr_element_code, $ps_placement_code, $pa_bundle_settings, $pa_options);
				
				$vs_field_id = 'ca_attribute_'.$pa_options['formName'].'_'.$vs_attr_element_code;
				
				if (!$vs_label_text) { $vs_label_text = $this->getAttributeLabel($vs_attr_element_code); }
				$vs_label = '<span class="formLabelText" id="'.$vs_field_id.'">'.$vs_label_text.'</span>'; 
				$vs_description =  (isset($pa_bundle_settings['description'][$g_ui_locale]) && $pa_bundle_settings['description'][$g_ui_locale]) ? $pa_bundle_settings['description'][$g_ui_locale]  : $this->getAttributeDescription($vs_attr_element_code);
				
				if ($t_element = $this->_getElementInstance($vs_attr_element_code)) {
					if ($o_config->get('show_required_field_marker') && (($t_element->getSetting('minChars') > 0) || ((bool)$t_element->getSetting('mustNotBeBlank')) || ((bool)$t_element->getSetting('requireValue')))) { 
						$vs_label .= ' '.$vs_required_marker;
					}
				}
				
				if (($vs_label_text) && ($vs_description)) {
					TooltipManager::add('#'.$vs_field_id, "<h3>{$vs_label_text}</h3>{$vs_description}");
				}
		
				break;
			# -------------------------------------------------
			case 'related_table':
				if (is_array($va_error_objects = $pa_options['request']->getActionErrors($ps_bundle_name, 'general')) && sizeof($va_error_objects)) {
					$vs_display_format = $o_config->get('bundle_element_error_display_format');
					foreach($va_error_objects as $o_e) {
						$va_errors[] = $o_e->getErrorDescription();
					}
				} else {
					$vs_display_format = $o_config->get('bundle_element_display_format');
				}
				
				switch($ps_bundle_name) {
					# -------------------------------
					case 'ca_object_representations':
					case 'ca_entities':
					case 'ca_places':
					case 'ca_occurrences':
					case 'ca_objects':
					case 'ca_collections':
					case 'ca_list_items':
					case 'ca_storage_locations':
					case 'ca_loans':
					case 'ca_movements':
					case 'ca_tour_stops':
						if (($ps_bundle_name != 'ca_object_representations') && ($this->_CONFIG->get($ps_bundle_name.'_disable'))) { return ''; }		// don't display if master "disable" switch is set
						$vs_element = $this->getRelatedHTMLFormBundle($pa_options['request'], $pa_options['formName'].'_'.$ps_bundle_name, $ps_bundle_name, $ps_placement_code, $pa_bundle_settings, $pa_options);	
						break;
					# -------------------------------
					case 'ca_object_lots':
						if ($this->_CONFIG->get($ps_bundle_name.'_disable')) { break; }		// don't display if master "disable" switch is set
						
						$pa_lot_options = array();
						if ($vn_lot_id = $pa_options['request']->getParameter('lot_id', pInteger)) {
							$pa_lot_options['force'][] = $vn_lot_id;
						}
						$vs_element = $this->getRelatedHTMLFormBundle($pa_options['request'], $pa_options['formName'].'_'.$ps_bundle_name, $ps_bundle_name, $ps_placement_code, $pa_bundle_settings, $pa_lot_options);	
						break;
					# -------------------------------
					case 'ca_representation_annotations':
						//if (!method_exists($this, "getAnnotationType") || !$this->getAnnotationType()) { continue; }	// don't show bundle if this representation doesn't support annotations
						//if (!method_exists($this, "useBundleBasedAnnotationEditor") || !$this->useBundleBasedAnnotationEditor()) { continue; }	// don't show bundle if this representation doesn't use bundles to edit annotations
						
						$pa_options['fields'] = array('ca_representation_annotations.status', 'ca_representation_annotations.access', 'ca_representation_annotations.props', 'ca_representation_annotations.representation_id');
						
						$vs_element = $this->getRepresentationAnnotationHTMLFormBundle($pa_options['request'], $pa_options['formName'].'_'.$ps_bundle_name, $ps_placement_code, $pa_bundle_settings, $pa_options);	

						break;
					# -------------------------------
					default:
						$vs_element = "'{$ps_bundle_name}' is not a valid related-table bundle name";
						break;
					# -------------------------------
				}
				
				if (!$vs_label_text) { $vs_label_text = $va_info['label']; }				
				$vs_label = '<span class="formLabelText" id="'.$pa_options['formName'].'_'.$ps_placement_code.'">'.$vs_label_text.'</span>'; 
				
				$vs_description = (isset($pa_bundle_settings['description'][$g_ui_locale]) && $pa_bundle_settings['description'][$g_ui_locale]) ? $pa_bundle_settings['description'][$g_ui_locale] : null;
				
				if (($vs_label_text) && ($vs_description)) {
					TooltipManager::add('#'.$pa_options['formName'].'_'.$ps_placement_code, "<h3>{$vs_label}</h3>{$vs_description}");
				}
				break;
			# -------------------------------------------------
			case 'special':
				if (is_array($va_error_objects = $pa_options['request']->getActionErrors($ps_bundle_name, 'general')) && sizeof($va_error_objects)) {
					$vs_display_format = $o_config->get('bundle_element_error_display_format');
					foreach($va_error_objects as $o_e) {
						$va_errors[] = $o_e->getErrorDescription();
					}
				} else {
					$vs_display_format = $o_config->get('bundle_element_display_format');
				}
				
				$vb_read_only = ($pa_options['request']->user->getBundleAccessLevel($this->tableName(), $ps_bundle_name) == __CA_BUNDLE_ACCESS_READONLY__) ? true : false;
				if (!$pa_bundle_settings['readonly']) { $pa_bundle_settings['readonly'] = (!isset($pa_bundle_settings['readonly']) || !$pa_bundle_settings['readonly']) ? $vb_read_only : true;	}
		
				
				switch($ps_bundle_name) {
					# -------------------------------
					// This bundle is only available when editing objects of type ca_representation_annotations
					case 'ca_representation_annotation_properties':
						foreach($this->getPropertyList() as $vs_property) {
							$vs_element .= $this->getPropertyHTMLFormBundle($pa_options['request'], $vs_property, $pa_options);
						}
						break;
					# -------------------------------
					// This bundle is only available when editing objects of type ca_sets
					case 'ca_set_items':
						$vs_element .= $this->getSetItemHTMLFormBundle($pa_options['request'], $pa_options['formName'].'_'.$ps_bundle_name, $pa_options);
						break;
					# -------------------------------
					// This bundle is only available for types which support set membership
					case 'ca_sets':
						require_once(__CA_MODELS_DIR__."/ca_sets.php");	// need to include here to avoid dependency errors on parse/compile
						$t_set = new ca_sets();
						$vs_element .= $t_set->getItemSetMembershipHTMLFormBundle($pa_options['request'], $pa_options['formName'].'_'.$ps_bundle_name, $this->tableNum(), $this->getPrimaryKey(), $pa_options['request']->getUserID(), $pa_bundle_settings, $pa_options);
						break;
					# -------------------------------
					// This bundle is only available when editing objects of type ca_editor_uis
					case 'ca_editor_ui_screens':
						$vs_element .= $this->getScreenHTMLFormBundle($pa_options['request'], $pa_options['formName'].'_'.$ps_bundle_name, $pa_options);
						break;
					# -------------------------------
					// This bundle is only available when editing objects of type ca_editor_uis
					case 'ca_editor_ui_type_restrictions':
						$vs_element .= $this->getTypeRestrictionsHTMLFormBundle($pa_options['request'], $pa_options['formName'].'_'.$ps_bundle_name, $pa_options);
						break;
					# -------------------------------
					// This bundle is only available when editing objects of type ca_editor_ui_screens
					case 'ca_editor_ui_screen_type_restrictions':
						$vs_element .= $this->getTypeRestrictionsHTMLFormBundle($pa_options['request'], $pa_options['formName'].'_'.$ps_bundle_name, $pa_options);
						break;
					# -------------------------------
					// This bundle is only available when editing objects of type ca_editor_ui_screens
					case 'ca_editor_ui_bundle_placements':
						$vs_element .= $this->getPlacementsHTMLFormBundle($pa_options['request'], $pa_options['formName'].'_'.$ps_bundle_name, $pa_options);
						break;
					# -------------------------------
					// This bundle is only available when editing objects of type ca_tours
					case 'ca_tour_stops_list':
						$vs_element .= $this->getTourStopHTMLFormBundle($pa_options['request'], $pa_options['formName'].'_'.$ps_bundle_name, $pa_options);
						break;
					# -------------------------------
					// This bundle is only available when editing objects of type ca_bundle_mappings
					case 'ca_bundle_mapping_groups':
						$vs_element .= $this->getGroupHTMLFormBundle($pa_options['request'], $pa_options['formName'].'_'.$ps_bundle_name, $pa_options);
						break;
					# -------------------------------
					// This bundle is only available when editing objects of type ca_bundle_mapping_groups
					case 'ca_bundle_mapping_rules':
						$vs_element .= $this->getRuleHTMLFormBundle($pa_options['request'], $pa_options['formName'].'_'.$ps_bundle_name, $pa_options);
						break;
					# -------------------------------
					// Hierarchy navigation bar for hierarchical tables
					case 'hierarchy_navigation':
						if ($this->isHierarchical()) {
							$vs_element .= $this->getHierarchyNavigationHTMLFormBundle($pa_options['request'], $pa_options['formName'], array(), $pa_bundle_settings, $pa_options);
						}
						break;
					# -------------------------------
					// Hierarchical item location control
					case 'hierarchy_location':
						if ($this->isHierarchical()) {
							$vs_element .= $this->getHierarchyLocationHTMLFormBundle($pa_options['request'], $pa_options['formName'], array(), $pa_bundle_settings, $pa_options);
						}
						break;
					# -------------------------------
					// This bundle is only available when editing objects of type ca_search_forms
					case 'ca_search_form_placements':
						//if (!$this->getPrimaryKey()) { return ''; }
						$vs_element .= $this->getSearchFormHTMLFormBundle($pa_options['request'], $pa_options['formName'].'_'.$ps_bundle_name, $pa_options);
						break;
					# -------------------------------
					// This bundle is only available when editing objects of type ca_bundle_displays
					case 'ca_bundle_display_placements':
						//if (!$this->getPrimaryKey()) { return ''; }
						$vs_element .= $this->getBundleDisplayHTMLFormBundle($pa_options['request'], $pa_options['formName'].'_'.$ps_bundle_name, $pa_options);
						break;
					# -------------------------------
					// 
					case 'ca_users':
						if (!$pa_options['request']->user->canDoAction('is_administrator') && ($pa_options['request']->getUserID() != $this->get('user_id'))) { return ''; }	// don't allow setting of per-user access if user is not owner
						$vs_element .= $this->getUserHTMLFormBundle($pa_options['request'], $pa_options['formName'].'_'.$ps_bundle_name, $this->tableNum(), $this->getPrimaryKey(), $pa_options['request']->getUserID(), $pa_options);
						break;
					# -------------------------------
					// 
					case 'ca_user_groups':
						if (!$pa_options['request']->user->canDoAction('is_administrator') && ($pa_options['request']->getUserID() != $this->get('user_id'))) { return ''; }	// don't allow setting of group access if user is not owner
						$vs_element .= $this->getUserGroupHTMLFormBundle($pa_options['request'], $pa_options['formName'].'_'.$ps_bundle_name, $this->tableNum(), $this->getPrimaryKey(), $pa_options['request']->getUserID(), $pa_options);
						break;
					# -------------------------------
					case 'settings':
						$vs_element .= $this->getHTMLSettingFormBundle($pa_options['request'], $pa_options['formName'].'_'.$ps_bundle_name, $pa_options);
						break;
					# -------------------------------
					// This bundle is only available when editing objects of type ca_object_representations
					case 'ca_object_representations_media_display':
						$vs_element .= $this->getMediaDisplayHTMLFormBundle($pa_options['request'], $pa_options['formName'], $ps_placement_code, $pa_bundle_settings, $pa_options);
						break;
					# -------------------------------
					default:
						$vs_element = "'{$ps_bundle_name}' is not a valid bundle name";
						break;
					# -------------------------------
				}
				
				
				if (!$vs_label_text) { 
					$vs_label_text = $va_info['label']; 
				}
				$vs_label = '<span class="formLabelText" id="'.$pa_options['formName'].'_'.$ps_placement_code.'">'.$vs_label_text.'</span>'; 
				
				$vs_description = (isset($pa_bundle_settings['description'][$g_ui_locale]) && $pa_bundle_settings['description'][$g_ui_locale]) ? $pa_bundle_settings['description'][$g_ui_locale] : null;
				
				if (($vs_label_text) && ($vs_description)) {
					TooltipManager::add('#'.$pa_options['formName'].'_'.$ps_placement_code, "<h3>{$vs_label}</h3>{$vs_description}");
				}
				
				break;
			# -------------------------------------------------
			default:
				return "'{$ps_bundle_name}' is not a valid bundle name";
				break;
			# -------------------------------------------------
		}
		
		$vs_output = str_replace("^ELEMENT", $vs_element, $vs_display_format);
		$vs_output = str_replace("^ERRORS", join('; ', $va_errors), $vs_output);
		$vs_output = str_replace("^LABEL", $vs_label, $vs_output);
		
		return $vs_output;
	}
	# ------------------------------------------------------
	public function getBundleList($pa_options=null) {
		if (isset($pa_options['includeBundleInfo']) && $pa_options['includeBundleInfo']) { 
			return $this->BUNDLES;
		}
		return array_keys($this->BUNDLES);
	}
	# ------------------------------------------------------
	public function isValidBundle($ps_bundle_name) {
		return (isset($this->BUNDLES[$ps_bundle_name]) && is_array($this->BUNDLES[$ps_bundle_name])) ? true : false;
	}
	# ------------------------------------------------------
 	/** 
 	  * Returns associative array with descriptive information about the bundle
 	  */
 	public function getBundleInfo($ps_bundle_name) {
 		return isset($this->BUNDLES[$ps_bundle_name]) ? $this->BUNDLES[$ps_bundle_name] : null;
 	}
 	# --------------------------------------------------------------------------------------------
	/**
	  * Returns display label for element specified by standard "get" bundle code (eg. <table_name>.<bundle_name> format)
	  */
	public function getDisplayLabel($ps_field) {
		$va_tmp = explode('.', $ps_field);
		if ((sizeof($va_tmp) == 2) && ($va_tmp[0] == $this->getLabelTableName()) && ($va_tmp[1] == $this->getLabelDisplayField())) {
			$va_tmp[0] = $this->tableName();
			$va_tmp[1] = 'preferred_labels';
			$ps_field = join('.', $va_tmp);
		}

		switch(sizeof($va_tmp)) {
			# -------------------------------------
			case 1:		// table_name
				if ($t_instance = $this->_DATAMODEL->getInstanceByTableName($va_tmp[0], true)) {
					return _t("Related %1", $t_instance->getProperty('NAME_PLURAL'));
				}
				break;
			# -------------------------------------
			case 2:		// table_name.field_name
			case 3:		// table_name.field_name.sub_element	
				if (!($t_instance = $this->_DATAMODEL->getInstanceByTableName($va_tmp[0], true))) { break; }
				$vs_prefix = $vs_suffix = '';
				$vs_suffix_string = ' ('._t('from related %1', $t_instance->getProperty('NAME_PLURAL')).')';
				if ($va_tmp[0] !== $this->tableName()) {
					$vs_suffix = $vs_suffix_string;
				}
				switch($va_tmp[1]) {
					# --------------------
					case 'related':
						unset($va_tmp[1]);
						$vs_label = $this->getDisplayLabel(join('.', $va_tmp));
						if ($va_tmp[0] != $this->tableName()) {
							return $vs_label.$vs_suffix_string;
						} 
						return $vs_label;
						break;
					# --------------------
					case 'preferred_labels':		
						if (method_exists($t_instance, 'getLabelTableInstance') && ($t_label_instance = $t_instance->getLabelTableInstance())) {
							if (!isset($va_tmp[2])) {
								return unicode_ucfirst($t_label_instance->getProperty('NAME_PLURAL')).$vs_suffix;
							} else {
								return unicode_ucfirst($t_label_instance->getDisplayLabel($t_label_instance->tableName().'.'.$va_tmp[2])).$vs_suffix;
							}
						}
						break;
					# --------------------
					case 'nonpreferred_labels':
						if (method_exists($t_instance, 'getLabelTableInstance') && ($t_label_instance = $t_instance->getLabelTableInstance())) {
							if ($va_tmp[0] !== $this->tableName()) {
								$vs_suffix = ' ('._t('alternates from related %1', $t_instance->getProperty('NAME_PLURAL')).')';
							} else {
								$vs_suffix = ' ('._t('alternates').')';
							}
							if (!isset($va_tmp[2])) {
								return unicode_ucfirst($t_label_instance->getProperty('NAME_PLURAL')).$vs_suffix;
							} else {
								return unicode_ucfirst($t_label_instance->getDisplayLabel($t_label_instance->tableName().'.'.$va_tmp[2])).$vs_suffix;
							}
						}
						break;
					# --------------------
					case 'media':		
						if ($va_tmp[0] === 'ca_object_representations') {
							if ($va_tmp[2]) {
								return _t('Object media representation (%1)', $va_tmp[2]);
							} else {
								return _t('Object media representation (default)');
							}
						}
						break;
					# --------------------
					default:
						if ($va_tmp[0] !== $this->tableName()) {
							return unicode_ucfirst($t_instance->getDisplayLabel($ps_field)).$vs_suffix;
						}
						break;
					# --------------------
				}	
					
				break;
			# -------------------------------------
		}
		
		// maybe it's a special bundle name?
		if (($va_tmp[0] === $this->tableName()) && isset($this->BUNDLES[$va_tmp[1]]) && $this->BUNDLES[$va_tmp[1]]['label']) {
			return $this->BUNDLES[$va_tmp[1]]['label'];
		}
		
		return parent::getDisplayLabel($ps_field);
	}
	# --------------------------------------------------------------------------------------------
	/**
	  * Returns display description for element specified by standard "get" bundle code (eg. <table_name>.<bundle_name> format)
	  */
	public function getDisplayDescription($ps_field) {
		$va_tmp = explode('.', $ps_field);
		if ((sizeof($va_tmp) == 2) && ($va_tmp[0] == $this->getLabelTableName()) && ($va_tmp[1] == $this->getLabelDisplayField())) {
			$va_tmp[0] = $this->tableName();
			$va_tmp[1] = 'preferred_labels';
			$ps_field = join('.', $va_tmp);
		}

		switch(sizeof($va_tmp)) {
			# -------------------------------------
			case 1:		// table_name
				if ($t_instance = $this->_DATAMODEL->getInstanceByTableName($va_tmp[0], true)) {
					return _t("A list of related %1", $t_instance->getProperty('NAME_PLURAL'));
				}
				break;
			# -------------------------------------
			case 2:		// table_name.field_name
			case 3:		// table_name.field_name.sub_element	
				if (!($t_instance = $this->_DATAMODEL->getInstanceByTableName($va_tmp[0], true))) { return null; }
				
				$vs_suffix = '';
				if ($va_tmp[0] !== $this->tableName()) {
					$vs_suffix = ' '._t('from related %1', $t_instance->getProperty('NAME_PLURAL'));
				}
				switch($va_tmp[1]) {
					# --------------------
					case 'related':
						unset($va_tmp[1]);
						return _t('A list of related %1', $t_instance->getProperty('NAME_PLURAL'));
						break;
					# --------------------
					case 'preferred_labels':								
						if (method_exists($t_instance, 'getLabelTableInstance') && ($t_label_instance = $t_instance->getLabelTableInstance())) {
							if (!isset($va_tmp[2])) {
								return _t('A list of %1 %2', $t_label_instance->getProperty('NAME_PLURAL'), $vs_suffix);
							} else {
								return _t('A list of %1 %2', $t_label_instance->getDisplayLabel($t_label_instance->tableName().'.'.$va_tmp[2]), $vs_suffix);
							}
						}
						break;
					# --------------------
					case 'nonpreferred_labels':						
						if (method_exists($t_instance, 'getLabelTableInstance') && ($t_label_instance = $t_instance->getLabelTableInstance())) {
							if (!isset($va_tmp[2])) {
								return _t('A list of alternate %1 %2', $t_label_instance->getProperty('NAME_PLURAL'), $vs_suffix);
							} else {
								return _t('A list of alternate %1 %2', $t_label_instance->getDisplayLabel($t_label_instance->tableName().'.'.$va_tmp[2]), $vs_suffix);
							}
						}
						break;
					# --------------------
					case 'media':		
						if ($va_tmp[0] === 'ca_object_representations') {
							if ($va_tmp[2]) {
								return _t('A list of related media representations using version "%1"', $va_tmp[2]);
							} else {
								return _t('A list of related media representations using the default version');
							}
						}
						break;
					# --------------------
					default:
						if ($va_tmp[0] !== $this->tableName()) {
							return _t('A list of %1 %2', $t_instance->getDisplayLabel($ps_field), $vs_suffix);
						}
						break;
					# --------------------
				}	
					
				break;
			# -------------------------------------
		}
		
		return parent::getDisplayDescription($ps_field);
	}
	# --------------------------------------------------------------------------------------------
	/**
	  * Returns HTML search form input widget for bundle specified by standard "get" bundle code (eg. <table_name>.<bundle_name> format)
	  * This method handles generation of search form widgets for (1) related tables (eg. ca_places),  preferred and non-preferred labels for both the 
	  * primary and related tables, and all other types of elements for related tables. If this method can't handle the bundle it will pass the request to the 
	  * superclass implementation of htmlFormElementForSearch()
	  *
	  * @param $po_request HTTPRequest
	  * @param $ps_field string
	  * @param $pa_options array
	  * @return string HTML text of form element. Will return null (from superclass) if it is not possible to generate an HTML form widget for the bundle.
	  * 
	  */
	public function htmlFormElementForSearch($po_request, $ps_field, $pa_options=null) {
		$va_tmp = explode('.', $ps_field);
		
		if (!in_array($va_tmp[0], array('created', 'modified'))) {
			switch(sizeof($va_tmp)) {
				# -------------------------------------
				case 1:		// table_name
					if ($va_tmp[0] != $this->tableName()) {
						if (!is_array($pa_options)) { $pa_options = array(); }
						if (!isset($pa_options['width'])) { $pa_options['width'] = 30; }
						if (!isset($pa_options['values'])) { $pa_options['values'] = array(); }
						if (!isset($pa_options['values'][$ps_field])) { $pa_options['values'][$ps_field] = ''; }
					
						return caHTMLTextInput($ps_field, array('value' => $pa_options['values'][$ps_field], 'size' => $pa_options['width'], 'id' => str_replace('.', '_', $ps_field)));
					}
					break;
				# -------------------------------------
				case 2:		// table_name.field_name
				case 3:		// table_name.field_name.sub_element	
					if (!($t_instance = $this->_DATAMODEL->getInstanceByTableName($va_tmp[0], true))) { return null; }
					
					switch($va_tmp[1]) {
						# --------------------
						case 'preferred_labels':		
						case 'nonpreferred_labels':
							return caHTMLTextInput($ps_field, array('value' => $pa_options['values'][$ps_field], 'size' => $pa_options['width'], 'id' => str_replace('.', '_', $ps_field)));
							break;
						# --------------------
						default:
							if ($va_tmp[0] != $this->tableName()) {
								return caHTMLTextInput($ps_field, array('value' => $pa_options['values'][$ps_field], 'size' => $pa_options['width'], 'id' => str_replace('.', '_', $ps_field)));
							}
							break;
						# --------------------
					}	
						
					break;
				# -------------------------------------
			}
		}
		
		return parent::htmlFormElementForSearch($po_request, $ps_field, $pa_options);
	}
 	# ------------------------------------------------------
 	/**
 	 * Returns a list of HTML fragments implementing all bundles in an HTML form for the specified screen
 	 * $pm_screen can be a screen tag (eg. "Screen5") or a screen_id (eg. 5) 
 	 *
 	 * @param mixed $pm_screen screen_id or code in default UI to return bundles for
 	 * @param array $pa_options Array of options. Supports and option getBundleFormHTML() supports plus:
 	 *		request = the current request object; used to apply user privs to bundle generation
 	 *		force = list of bundles to force onto form if they are not included in the UI; forced bundles will be included at the bottom of the form
 	 *		forceHidden = list of *intrinsic* fields to force onto form as hidden <input> elements if they are not included in the UI; NOTE: only intrinsic fields may be specified
 	 *		omit = list of bundles to omit from form in the event they are included in the UI
 	 *	@return array List of bundle HTML to display in form, keyed on placement code
 	 */
 	public function getBundleFormHTMLForScreen($pm_screen, $pa_options) {
 		$va_omit_bundles = (isset($pa_options['omit']) && is_array($pa_options['omit'])) ? $pa_options['omit'] : array();
 		
 		if (isset($pa_options['ui_instance']) && ($pa_options['ui_instance'])) {
 			$t_ui = $pa_options['ui_instance'];
 		} else {
 			$t_ui = ca_editor_uis::loadDefaultUI($this->tableName(), $pa_options['request'], $this->getTypeID());
 		}
 		
 		$va_bundles = $t_ui->getScreenBundlePlacements($pm_screen);
 
 		$va_bundle_html = array();
 		
 		$vn_pk_id = $this->getPrimaryKey();
		
		$va_bundles_present = array();
		if (is_array($va_bundles)) {
			$vs_type_id_fld = isset($this->ATTRIBUTE_TYPE_ID_FLD) ? $this->ATTRIBUTE_TYPE_ID_FLD : null;
			$vs_hier_parent_id_fld = isset($this->HIERARCHY_PARENT_ID_FLD) ? $this->HIERARCHY_PARENT_ID_FLD : null;
			foreach($va_bundles as $va_bundle) {
				if ($va_bundle['bundle_name'] === $vs_type_id_fld) { continue; }	// skip type_id
				if ((!$vn_pk_id) && ($va_bundle['bundle_name'] === $vs_hier_parent_id_fld)) { continue; }
				if (in_array($va_bundle['bundle_name'], $va_omit_bundles)) { continue; }
				
				// Test for user action restrictions on intrinsic fields
				$vb_output_bundle = true;
				if ($this->hasField($va_bundle['bundle_name'])) {
					if (is_array($va_requires = $this->getFieldInfo($va_bundle['bundle_name'], 'REQUIRES'))) {
						foreach($va_requires as $vs_required_action) {
							if (!$pa_options['request']->user->canDoAction($vs_required_action)) { 
								$vb_output_bundle = false;
								break;
							}
						}
					}
				}
				if (!$vb_output_bundle) { continue; }
				$va_bundle_html[$va_bundle['placement_code']] = $this->getBundleFormHTML($va_bundle['bundle_name'], $va_bundle['placement_code'], $va_bundle['settings'], $pa_options);
				$va_bundles_present[$va_bundle['bundle_name']] = true;
			}
		}
		
		// is this a form to create a new item?
		if (!$vn_pk_id) {
			// auto-add mandatory fields if this is a new object
			$va_mandatory_fields = $this->getMandatoryFields();
			foreach($va_mandatory_fields as $vs_field) {
				if (!isset($va_bundles_present[$vs_field]) || !$va_bundles_present[$vs_field]) {
					$va_bundle_html[$vs_field] = $this->getBundleFormHTML($vs_field, 'mandatory_'.$vs_field, array(), $pa_options);
				}
			}
			
			// add type_id
			if (isset($this->ATTRIBUTE_TYPE_ID_FLD) && $this->ATTRIBUTE_TYPE_ID_FLD) {
				$va_bundle_html[$this->ATTRIBUTE_TYPE_ID_FLD] = caHTMLHiddenInput($this->ATTRIBUTE_TYPE_ID_FLD, array('value' => $pa_options['request']->getParameter($this->ATTRIBUTE_TYPE_ID_FLD, pInteger)));
			}
			
			// add parent_id
			if (isset($this->HIERARCHY_PARENT_ID_FLD) && $this->HIERARCHY_PARENT_ID_FLD) {
				$va_bundle_html[$this->HIERARCHY_PARENT_ID_FLD] = caHTMLHiddenInput($this->HIERARCHY_PARENT_ID_FLD, array('value' => $pa_options['request']->getParameter($this->HIERARCHY_PARENT_ID_FLD, pInteger)));
			}
			
			// add forced bundles
			if (isset($pa_options['force']) && $pa_options['force']) {
				if (!is_array($pa_options['force'])) { $pa_options['force'] = array($pa_options['force']); }
				foreach($pa_options['force'] as $vn_x => $vs_bundle) {
					if (!isset($va_bundles_present[$vs_bundle]) || !$va_bundles_present[$vs_bundle]) {
						$va_bundle_html['_force_'.$vs_bundle] = $this->getBundleFormHTML($vs_bundle, 'force_'.$vs_field, array(), $pa_options);
					}
				}
			}
			
			// add forced hidden intrinsic fields
			if (isset($pa_options['forceHidden']) && $pa_options['forceHidden']) {
				if (!is_array($pa_options['forceHidden'])) { $pa_options['forceHidden'] = array($pa_options['forceHidden']); }
				foreach($pa_options['forceHidden'] as $vn_x => $vs_field) {
					if (!isset($va_bundles_present[$vs_field]) || !$va_bundles_present[$vs_field]) {
						$va_bundle_html['_force_hidden_'.$vs_field] = caHTMLHiddenInput($vs_field, array('value' => $pa_options['request']->getParameter($vs_field, pString)));
					}
				}
			}
		}
		
 		return $va_bundle_html;
 	}
 	# ------------------------------------------------------
 	/**
 	 *
 	 */
	public function getHierarchyNavigationHTMLFormBundle($po_request, $ps_form_name, $pa_options=null, $pa_bundle_settings=null) {
		$vs_view_path = (isset($pa_options['viewPath']) && $pa_options['viewPath']) ? $pa_options['viewPath'] : $po_request->getViewsDirectoryPath();
		$o_view = new View($po_request, "{$vs_view_path}/bundles/");
		
		if(!is_array($pa_bundle_settings)) { $pa_bundle_settings = array(); }
		
		if (!($vs_label_table_name = $this->getLabelTableName())) { return ''; }
		
		$o_view->setVar('id_prefix', $ps_form_name);
		$o_view->setVar('t_subject', $this);
		if (!($vn_id = $this->getPrimaryKey())) {
			$vn_id = $po_request->getParameter($this->HIERARCHY_PARENT_ID_FLD, pString);
		} 
		
		$vs_display_fld = $this->getLabelDisplayField();
		if (!($va_ancestor_list = $this->getHierarchyAncestors($vn_id, array(
			'additionalTableToJoin' => $vs_label_table_name, 
			'additionalTableJoinType' => 'LEFT',
			'additionalTableSelectFields' => array($vs_display_fld, 'locale_id'),
			'additionalTableWheres' => array('('.$vs_label_table_name.'.is_preferred = 1 OR '.$vs_label_table_name.'.is_preferred IS NULL)'),
			'includeSelf' => true
		)))) {
			$va_ancestor_list = array();
		}
		
		$va_ancestors_by_locale = array();
		$vs_pk = $this->primaryKey();
		
		$vs_idno_field = $this->getProperty('ID_NUMBERING_ID_FIELD');
		foreach($va_ancestor_list as $vn_ancestor_id => $va_info) {
			if (!$va_info['NODE']['parent_id']) { continue; }
			if (!($va_info['NODE']['name'] =  $va_info['NODE'][$vs_display_fld])) {		// copy display field content into 'name' which is used by bundle for display
				if (!($va_info['NODE']['name'] = $va_info['NODE'][$vs_idno_field])) { $va_info['NODE']['name'] = '???'; }
			}
			$va_ancestors_by_locale[$va_info['NODE'][$vs_pk]][$va_info['NODE']['locale_id']] = $va_info['NODE'];
		}
		$va_ancestor_list = array_reverse(caExtractValuesByUserLocale($va_ancestors_by_locale));
		
		// push hierarchy name onto front of list
		if ($vs_hier_name = $this->getHierarchyName($vn_id)) {
			array_unshift($va_ancestor_list, array(
				'name' => $vs_hier_name
			));
		}
		
		if (!$this->getPrimaryKey()) {
			$va_ancestor_list[null] = array(
				$this->primaryKey() => '',
				$this->getLabelDisplayField() => _t('New %1', $this->getProperty('NAME_SINGULAR'))
			);
		}
		
		if (method_exists($this, "getTypeList")) {
			$o_view->setVar('type_list', $this->getTypeList());
		}
		
		$o_view->setVar('ancestors', $va_ancestor_list);
		$o_view->setVar('id', $this->getPrimaryKey());
		$o_view->setVar('settings', $pa_bundle_settings);
		
		return $o_view->render('hierarchy_navigation.php');
	}
	# ------------------------------------------------------
	/**
	 *
	 */
	public function getHierarchyLocationHTMLFormBundle($po_request, $ps_form_name, $pa_options=null, $pa_bundle_settings=null) {
		$vs_view_path = (isset($pa_options['viewPath']) && $pa_options['viewPath']) ? $pa_options['viewPath'] : $po_request->getViewsDirectoryPath();
		$o_view = new View($po_request, "{$vs_view_path}/bundles/");
		
		if(!is_array($pa_bundle_settings)) { $pa_bundle_settings = array(); }
		
		if (!($vs_label_table_name = $this->getLabelTableName())) { return ''; }
		
		$o_view->setVar('id_prefix', $ps_form_name);
		$o_view->setVar('t_subject', $this);
		if (!($vn_id = $this->getPrimaryKey())) {
			$vn_parent_id = $vn_id = $po_request->getParameter($this->HIERARCHY_PARENT_ID_FLD, pString);
		} else {
			$vn_parent_id = $this->get($this->HIERARCHY_PARENT_ID_FLD);
		}
		$vs_display_fld = $this->getLabelDisplayField();
		
		if ($this->supportsPreferredLabelFlag()) {
			if (!($va_ancestor_list = $this->getHierarchyAncestors($vn_id, array(
				'additionalTableToJoin' => $vs_label_table_name, 
				'additionalTableJoinType' => 'LEFT',
				'additionalTableSelectFields' => array($vs_display_fld, 'locale_id'),
				'additionalTableWheres' => array('('.$vs_label_table_name.'.is_preferred = 1 OR '.$vs_label_table_name.'.is_preferred IS NULL)'),
				'includeSelf' => true
			)))) {
				$va_ancestor_list = array();
			}
		} else {
			if (!($va_ancestor_list = $this->getHierarchyAncestors($vn_id, array(
				'additionalTableToJoin' => $vs_label_table_name, 
				'additionalTableJoinType' => 'LEFT',
				'additionalTableSelectFields' => array($vs_display_fld, 'locale_id'),
				'includeSelf' => true
			)))) {
				$va_ancestor_list = array();
			}
		}
		
		
		$va_ancestors_by_locale = array();
		$vs_pk = $this->primaryKey();
		
		
		$vs_idno_field = $this->getProperty('ID_NUMBERING_ID_FIELD');
		foreach($va_ancestor_list as $vn_ancestor_id => $va_info) {
			if (!$va_info['NODE']['parent_id']) { continue; }
			if (!($va_info['NODE']['name'] =  $va_info['NODE'][$vs_display_fld])) {		// copy display field content into 'name' which is used by bundle for display
				if (!($va_info['NODE']['name'] = $va_info['NODE'][$vs_idno_field])) { $va_info['NODE']['name'] = '???'; }
			}
			$vn_locale_id = isset($va_info['NODE']['locale_id']) ? $va_info['NODE']['locale_id'] : null;
			$va_ancestors_by_locale[$va_info['NODE'][$vs_pk]][$vn_locale_id] = $va_info['NODE'];
		}
		
		$va_ancestor_list = array_reverse(caExtractValuesByUserLocale($va_ancestors_by_locale));
		
		// push hierarchy name onto front of list
		if ($vs_hier_name = $this->getHierarchyName($vn_id)) {
			array_unshift($va_ancestor_list, array(
				'name' => $vs_hier_name
			));
		}
		if (!$this->getPrimaryKey()) {
			$va_ancestor_list[null] = array(
				$this->primaryKey() => '',
				$this->getLabelDisplayField() => _t('New %1', $this->getProperty('NAME_SINGULAR'))
			);
		}
		
		$o_view->setVar('parent_id', $vn_parent_id);
		$o_view->setVar('ancestors', $va_ancestor_list);
		$o_view->setVar('id', $this->getPrimaryKey());
		$o_view->setVar('settings', $pa_bundle_settings);
		
		return $o_view->render('hierarchy_location.php');
	}
 	# ------------------------------------------------------
 	/**
 	 *
 	 */
	public function getRelatedHTMLFormBundle($po_request, $ps_form_name, $ps_related_table, $ps_placement_code=null, $pa_bundle_settings=null, $pa_options=null) {
		global $g_ui_locale;
		
		JavascriptLoadManager::register('sortableUI');
		
		if(!is_array($pa_bundle_settings)) { $pa_bundle_settings = array(); }
		if(!is_array($pa_options)) { $pa_options = array(); }
		
		$vs_view_path = (isset($pa_options['viewPath']) && $pa_options['viewPath']) ? $pa_options['viewPath'] : $po_request->getViewsDirectoryPath();
		$o_view = new View($po_request, "{$vs_view_path}/bundles/");
		
		$t_item = $this->getAppDatamodel()->getTableInstance($ps_related_table);
		
		switch(sizeof($va_path = array_keys($this->getAppDatamodel()->getPath($this->tableName(), $ps_related_table)))) {
			case 3:
				// many-many relationship
				$t_item_rel = $this->getAppDatamodel()->getTableInstance($va_path[1]);
				break;
			case 2:
				// many-one relationship
				$t_item_rel = $this->getAppDatamodel()->getTableInstance($va_path[1]);
				break;
			default:
				$t_item_rel = null;
				break;
		}
	
		$o_view->setVar('id_prefix', $ps_form_name);
		$o_view->setVar('t_instance', $this);
		$o_view->setVar('t_item', $t_item);
		$o_view->setVar('t_item_rel', $t_item_rel);
		
		$vb_read_only = ($po_request->user->getBundleAccessLevel($this->tableName(), $ps_related_table) == __CA_BUNDLE_ACCESS_READONLY__) ? true : false;
		if (!$pa_bundle_settings['readonly']) { $pa_bundle_settings['readonly'] = (!isset($pa_bundle_settings['readonly']) || !$pa_bundle_settings['readonly']) ? $vb_read_only : true;	}
		
		// pass bundle settings
		$o_view->setVar('settings', $pa_bundle_settings);
		$o_view->setVar('graphicsPath', $pa_options['graphicsPath']);
		
		// pass placement code
		$o_view->setVar('placement_code', $ps_placement_code);
		
		$o_view->setVar('add_label', isset($pa_bundle_settings['add_label'][$g_ui_locale]) ? $pa_bundle_settings['add_label'][$g_ui_locale] : null);
		
		$t_label = null;
		if ($t_item->getLabelTableName()) {
			$t_label = $this->_DATAMODEL->getInstanceByTableName($t_item->getLabelTableName(), true);
		}
		
		if (method_exists($t_item_rel, 'getRelationshipTypes')) {
			$o_view->setVar('relationship_types', $t_item_rel->getRelationshipTypes(null, null,  array_merge($pa_options, $pa_bundle_settings)));
			$o_view->setVar('relationship_types_by_sub_type', $t_item_rel->getRelationshipTypesBySubtype($this->tableName(), $this->get('type_id'),  array_merge($pa_options, $pa_bundle_settings)));
		}
		$o_view->setVar('t_subject', $this);
		
		
		$va_initial_values = array();
		if (sizeof($va_items = $this->getRelatedItems($ps_related_table, array_merge($pa_options, $pa_bundle_settings)))) {
			$t_rel = $this->getAppDatamodel()->getInstanceByTableName($ps_related_table, true);
			$vs_rel_pk = $t_rel->primaryKey();
			$va_ids = caExtractArrayValuesFromArrayOfArrays($va_items, $vs_rel_pk);
			$qr_rel_items = $t_item->makeSearchResult($t_rel->tableNum(), $va_ids);	
			
			$va_initial_values = caProcessRelationshipLookupLabel($qr_rel_items, $t_rel, array('relatedItems' => $va_items, 'stripTags' => true));
		}
		
		$va_force_new_values = array();
		if (isset($pa_options['force']) && is_array($pa_options['force'])) {
			foreach($pa_options['force'] as $vn_id) {
				if ($t_item->load($vn_id)) {
					$va_item = $t_item->getFieldValuesArray();
					if ($t_label) {
						$va_item[$t_label->getDisplayField()] =  $t_item->getLabelForDisplay();
					}
					$va_force_new_values[$vn_id] = array_merge(
						$va_item, 
						array(
							'id' => $vn_id, 
							'idno' => ($vn_idno = $t_item->get('idno')) ? $vn_idno : null, 
							'idno_stub' => ($vn_idno_stub = $t_item->get('idno_stub')) ? $vn_idno_stub : null, 
							'relationship_type_id' => null
						)
					);
				}
			}
		}
		
		$o_view->setVar('initialValues', $va_initial_values);
		$o_view->setVar('forceNewValues', $va_force_new_values);
		
		return $o_view->render($ps_related_table.'.php');
	}
	# ------------------------------------------------------
	/**
	* saves all bundles on the specified screen in the database by extracting 
	* required data from the supplied request
	* $pm_screen can be a screen tag (eg. "Screen5") or a screen_id (eg. 5)
	*
	* Calls processBundlesBeforeBaseModelSave() method in subclass right before invoking insert() or update() on
	* the BaseModel, if the method is defined. Passes the following parameters to processBundlesBeforeBaseModelSave():
	*		array $pa_bundles An array of bundles to be saved
	*		string $ps_form_prefix The form prefix
	*		RequestHTTP $po_request The current request
	*		array $pa_options Optional array of parameters; expected to be the same as that passed to saveBundlesForScreen()
	*
	* The processBundlesBeforeBaseModelSave() is useful for those odd cases where you need to do some processing before the basic
	* database record defined by the model (eg. intrinsic fields and hierarchy coding) is inserted or updated. You usually don't need 
	* to use it.
	*/
	public function saveBundlesForScreen($pm_screen, $po_request, $pa_options=null) {
		$vb_we_set_transaction = false;
		
		if (!$this->inTransaction()) {
			$this->setTransaction(new Transaction($this->getDb()));
			$vb_we_set_transaction = true;
		}
		
		BaseModel::setChangeLogUnitID();
		// get items on screen
		if (isset($pa_options['ui_instance']) && ($pa_options['ui_instance'])) {
 			$t_ui = $pa_options['ui_instance'];
 		} else {
			$t_ui = ca_editor_uis::loadDefaultUI($this->tableName(), $po_request, $this->getTypeID());
 		}
		$va_bundles = $t_ui->getScreenBundlePlacements($pm_screen);
		
		// sort fields by type
		$va_fields_by_type = array();
		if (is_array($va_bundles)) {
			foreach($va_bundles as $vn_i => $va_tmp) {
				if (isset($va_tmp['settings']['readonly']) && (bool)$va_tmp['settings']['readonly']) { continue; }			// don't attempt to save "read-only" bundles
				
				if (($po_request->user->getBundleAccessLevel($this->tableName(), $va_tmp['bundle_name'])) < __CA_BUNDLE_ACCESS_EDIT__) {	// don't save bundles use doesn't have edit access for
					continue;
				}
				$va_info = $this->getBundleInfo($va_tmp['bundle_name']);
				$va_fields_by_type[$va_info['type']][$va_tmp['placement_code']] = $va_tmp['bundle_name'];
			}
		}
		
		$vs_form_prefix = $po_request->getParameter('_formName', pString);
		
		// auto-add mandatory fields if this is a new object
		if (!is_array($va_fields_by_type['intrinsic'])) { $va_fields_by_type['intrinsic'] = array(); }
		if (!$this->getPrimaryKey()) {
			if (is_array($va_mandatory_fields = $this->getMandatoryFields())) {
				foreach($va_mandatory_fields as $vs_field) {
					if (!in_array($vs_field, $va_fields_by_type['intrinsic'])) {
						$va_fields_by_type['intrinsic'][] = $vs_field;
					}
				}
			}
			
			// add parent_id
			if ($this->HIERARCHY_PARENT_ID_FLD) {
				$va_fields_by_type['intrinsic'][] = $this->HIERARCHY_PARENT_ID_FLD;
			}
		}
		
		// auto-add lot_id if it's set in the request and not already on the list (supports "add new object to lot" functionality)
		if (($this->tableName() == 'ca_objects') && (!in_array('lot_id', $va_fields_by_type['intrinsic'])) && ($po_request->getParameter('lot_id', pInteger))) {
			$va_fields_by_type['intrinsic'][] = 'lot_id';
		}
		
		// save intrinsic fields
		if (is_array($va_fields_by_type['intrinsic'])) {
			$vs_idno_field = $this->getProperty('ID_NUMBERING_ID_FIELD');
			foreach($va_fields_by_type['intrinsic'] as $vs_f) {
				if (isset($_FILES[$vs_f]) && $_FILES[$vs_f]) {
					// media field
					$this->set($vs_f, $_FILES[$vs_f]['tmp_name'], array('original_filename' => $_FILES[$vs_f]['name']));
				} else {
					switch($vs_f) {
						case $vs_idno_field:
							if ($this->opo_idno_plugin_instance) {
								$this->opo_idno_plugin_instance->setDb($this->getDb());
								$this->set($vs_f, $vs_tmp = $this->opo_idno_plugin_instance->htmlFormValue($vs_idno_field));
							} else {
								$this->set($vs_f, $po_request->getParameter($vs_f, pString));
							}
							break;
						default:
							$this->set($vs_f, $po_request->getParameter($vs_f, pString));
							break;
					}
				}
				if ($this->numErrors() > 0) {
					foreach($this->errors() as $o_e) {
						switch($o_e->getErrorNumber()) {
							case 795:
								// field conflicts
								foreach($this->getFieldConflicts() as $vs_conflict_field) {
									$po_request->addActionError($o_e, $vs_conflict_field);
								}
								break;
							default:
								$po_request->addActionError($o_e, $vs_f);
								break;
						}
					}
				}
			}
		}
		
		// save attributes
		$va_inserted_attributes_by_element = array();
		if (isset($va_fields_by_type['attribute']) && is_array($va_fields_by_type['attribute'])) {
			//
			// name of attribute request parameters are:
			// 	For new attributes
			// 		{$vs_form_prefix}_attribute_{element_set_id}_{element_id|'locale_id'}_new_{n}
			//		ex. ObjectBasicForm_attribute_6_locale_id_new_0 or ObjectBasicForm_attribute_6_desc_type_new_0
			//
			// 	For existing attributes:
			// 		{$vs_form_prefix}_attribute_{element_set_id}_{element_id|'locale_id'}_{attribute_id}
			//
			
			// look for newly created attributes; look for attributes to delete
			$va_inserted_attributes = array();
			$reserved_elements = array();
			foreach($va_fields_by_type['attribute'] as $vs_placement_code => $vs_f) {
				$vs_element_set_code = preg_replace("/^ca_attribute_/", "", $vs_f);
				//does the attribute's datatype have a saveElement method - if so, use that instead
				$vs_element = $this->_getElementInstance($vs_element_set_code);
				$vn_element_id = $vs_element->getPrimaryKey();
				$vs_element_datatype = $vs_element->get('datatype');
				$vs_datatype = Attribute::getValueInstance($vs_element_datatype);
				if(method_exists($vs_datatype,'saveElement')) {
					$reserved_elements[] = $vs_element;
					continue;
				}
				
				$va_attributes_to_insert = array();
				$va_attributes_to_delete = array();
				$va_locales = array();
				foreach($_REQUEST as $vs_key => $vs_val) {
					// is it a newly created attribute?
					if (preg_match('/'.$vs_placement_code.$vs_form_prefix.'_attribute_'.$vn_element_id.'_([\w\d\-_]+)_new_([\d]+)/', $vs_key, $va_matches)) { 
						$vn_c = intval($va_matches[2]);
						// yep - grab the locale and value
						$vn_locale_id = isset($_REQUEST[$vs_placement_code.$vs_form_prefix.'_attribute_'.$vn_element_id.'_locale_id_new_'.$vn_c]) ? $_REQUEST[$vs_placement_code.$vs_form_prefix.'_attribute_'.$vn_element_id.'_locale_id_new_'.$vn_c] : null;
						
						//if(strlen(trim($vs_val))>0) {
							$va_inserted_attributes_by_element[$vn_element_id][$vn_c]['locale_id'] = $va_attributes_to_insert[$vn_c]['locale_id'] = $vn_locale_id; 
							$va_inserted_attributes_by_element[$vn_element_id][$vn_c][$va_matches[1]] = $va_attributes_to_insert[$vn_c][$va_matches[1]] = $vs_val;
						//}
					} else {
						// is it a delete key?
						if (preg_match('/'.$vs_placement_code.$vs_form_prefix.'_attribute_'.$vn_element_id.'_([\d]+)_delete/', $vs_key, $va_matches)) {
							$vn_attribute_id = intval($va_matches[1]);
							$va_attributes_to_delete[$vn_attribute_id] = true;
						}
					}
				}
				
				// look for uploaded files as attributes
				foreach($_FILES as $vs_key => $va_val) {
					if (preg_match('/'.$vs_placement_code.$vs_form_prefix.'_attribute_'.$vn_element_id.'_locale_id_new_([\d]+)/', $vs_key, $va_locale_matches)) { 
						$vn_locale_c = intval($va_locale_matches[1]);
						$va_locales[$vn_locale_c] = $vs_val;
						continue; 
					}
					// is it a newly created attribute?
					if (preg_match('/'.$vs_placement_code.$vs_form_prefix.'_attribute_'.$vn_element_id.'_([\w\d\-_]+)_new_([\d]+)/', $vs_key, $va_matches)) { 
						if (!$va_val['size']) { continue; }	// skip empty files
						
						// yep - grab the value
						$vn_c = intval($va_matches[2]);
						$va_inserted_attributes_by_element[$vn_element_id][$vn_c]['locale_id'] = $va_attributes_to_insert[$vn_c]['locale_id'] = $va_locales[$vn_c]; 
						$va_val['_uploaded_file'] = true;
						$va_inserted_attributes_by_element[$vn_element_id][$vn_c][$va_matches[1]] = $va_attributes_to_insert[$vn_c][$va_matches[1]] = $va_val;
					}
				}
				
				
				// do deletes
				$this->clearErrors();
				foreach($va_attributes_to_delete as $vn_attribute_id => $vb_tmp) {
					$this->removeAttribute($vn_attribute_id, $vs_f, array('pending_adds' => $va_attributes_to_insert));
				}
				
				// do inserts
				foreach($va_attributes_to_insert as $va_attribute_to_insert) {
					$this->clearErrors();
					$this->addAttribute($va_attribute_to_insert, $vn_element_id, $vs_f);
				}
				
				// check for attributes to update
				if (is_array($va_attrs = $this->getAttributesByElement($vn_element_id))) {
					$t_element = new ca_metadata_elements();
							
					$va_attrs_update_list = array();
					foreach($va_attrs as $o_attr) {
						$this->clearErrors();
						$vn_attribute_id = $o_attr->getAttributeID();
						if (isset($va_inserted_attributes[$vn_attribute_id]) && $va_inserted_attributes[$vn_attribute_id]) { continue; }
						if (isset($va_attributes_to_delete[$vn_attribute_id]) && $va_attributes_to_delete[$vn_attribute_id]) { continue; }
						
						$vn_element_set_id = $o_attr->getElementID();
						
						$va_attr_update = array(
							'locale_id' =>  $po_request->getParameter($vs_placement_code.$vs_form_prefix.'_attribute_'.$vn_element_set_id.'_locale_id_'.$vn_attribute_id, pString) 
						);
						
						//
						// Check to see if there are any values in the element set that are not in the  attribute we're editing
						// If additional sub-elements were added to the set after the attribute we're updating was created
						// those sub-elements will not have corresponding values returned by $o_attr->getValues() above.
						// Because we use the element_ids in those values to pull request parameters, if an element_id is missing
						// it effectively becomes invisible and cannot be set. This is a fairly unusual case but it happens, and when it does
						// it's really annoying. It would be nice and efficient to simply create the missing values at configuration time, but we wouldn't
						// know what to set the values to. So what we do is, after setting all of the values present in the attribute from the request, grab
						// the configuration for the element set and see if there are any elements in the set that we didn't get values for.
						//
						$va_sub_elements = $t_element->getElementsInSet($vn_element_set_id);
						foreach($va_sub_elements as $vn_i => $va_element_info) {
							if ($va_element_info['datatype'] == 0) { continue; }
							//$vn_element_id = $o_attr_val->getElementID();
							$vn_element_id = $va_element_info['element_id'];
							
							$vs_k = $vs_placement_code.$vs_form_prefix.'_attribute_'.$vn_element_set_id.'_'.$vn_element_id.'_'.$vn_attribute_id;
							if (isset($_FILES[$vs_k]) && ($va_val = $_FILES[$vs_k])) {
								if ($va_val['size'] > 0) {	// is there actually a file?
									$va_val['_uploaded_file'] = true;
									$va_attr_update[$vn_element_id] = $va_val;
									continue;
								}
							} 
							$vs_attr_val = $po_request->getParameter($vs_k, pString);
							$va_attr_update[$vn_element_id] = $vs_attr_val;
						}
						
						$this->clearErrors();
						//print "EDIT $vn_attribute_id/$vn_element_set_id/$vs_f/".print_R($va_attr_update, true)."<Br>\n";
						$this->editAttribute($vn_attribute_id, $vn_element_set_id, $va_attr_update, $vs_f);
					}
				}
			}
		}
		
		if ($this->getPrimaryKey() && $this->HIERARCHY_PARENT_ID_FLD && ($vn_parent_id = $po_request->getParameter($vs_form_prefix.'HierLocation_new_parent_id', pInteger))) {
			$this->set($this->HIERARCHY_PARENT_ID_FLD, $vn_parent_id);
		} else {
			if ($this->getPrimaryKey() && $this->HIERARCHY_PARENT_ID_FLD && ($this->HIERARCHY_TYPE == __CA_HIER_TYPE_ADHOC_MONO__) && isset($_REQUEST[$vs_form_prefix.'HierLocation_new_parent_id']) && (!(bool)$_REQUEST[$vs_form_prefix.'HierLocation_new_parent_id'])) {
				$this->set($this->HIERARCHY_PARENT_ID_FLD, null);
				$this->set($this->HIERARCHY_ID_FLD, $this->getPrimaryKey());
			}
		}
		
		//
		// Call processBundlesBeforeBaseModelSave() method in sub-class, if it is defined. The method is passed
		// a list of bundles, the form prefix, the current request and the options passed to saveBundlesForScreen() –
		// everything needed to perform custom processing using the incoming form content that is being saved.
		// 
		// A processBundlesBeforeBaseModelSave() method is rarely needed, but can be handy when you need to do something model-specific
		// right before the basic database record is committed via insert() (for new records) or update() (for existing records).
		// For example, the media in ca_object_representations is set in a "special" bundle, which provides a specialized media upload UI. Unfortunately "special's" 
		// are handled after the basic database record is saved via insert() or update(), while the actual media must be set prior to the save.
		// processBundlesBeforeBaseModelSave() allows special logic in the ca_object_representations model to be invoked to set the media before the insert() or update().
		// The "special" takes care of other functions after the insert()/update()
		//
		if (method_exists($this, "processBundlesBeforeBaseModelSave")) {
			$this->processBundlesBeforeBaseModelSave($va_bundles, $vs_form_prefix, $po_request, $pa_options);
		}
		
		$this->setMode(ACCESS_WRITE);
			
		$vb_is_insert = false;
		if ($this->getPrimaryKey()) {
			$this->update();
		} else {
			$this->insert();
			$vb_is_insert = true;
		}
		if ($this->numErrors() > 0) {
			$va_errors = array();
			foreach($this->errors() as $o_e) {
				switch($o_e->getErrorNumber()) {
					case 2010:
						$po_request->addActionErrors(array($o_e), 'hierarchy_location');
						break;
					case 795:
						// field conflict
						foreach($this->getFieldConflicts() as $vs_conflict_field) {
							$po_request->addActionError($o_e, $vs_conflict_field);
						}
						break;
					case 1100:
						if ($vs_idno_field = $this->getProperty('ID_NUMBERING_ID_FIELD')) {
							$po_request->addActionError($o_e, $this->getProperty('ID_NUMBERING_ID_FIELD'));
						}
						break;
					default:
						$va_errors[] = $o_e;
						break;
				}
			}
			//print_r($this->getErrors());
			$po_request->addActionErrors($va_errors);
			
			if ($vb_is_insert) {
			 	BaseModel::unsetChangeLogUnitID();
			 	if ($vb_we_set_transaction) { $this->removeTransaction(false); }
				return false;	// bail on insert error
			}
		}
		
		if (!$this->getPrimaryKey()) { 
			BaseModel::unsetChangeLogUnitID(); 
			if ($vb_we_set_transaction) { $this->removeTransaction(false); }
			return false; 
		}	// bail if insert failed
		
		$this->clearErrors();
		
		//save reserved elements -  those with a saveElement method
		if (isset($reserved_elements) && is_array($reserved_elements)) {
			foreach($reserved_elements as $res_element) {
				$res_element_id = $res_element->getPrimaryKey();
				$res_element_datatype = $res_element->get('datatype');
				$res_datatype = Attribute::getValueInstance($res_element_datatype);
				$res_datatype->saveElement($this,$res_element,$vs_form_prefix,$po_request);
			}
		}
		
		// save preferred labels
		$vb_check_for_dupe_labels = $this->_CONFIG->get('allow_duplicate_labels_for_'.$this->tableName()) ? false : true;
		$vb_error_inserting_pref_label = false;
		if (is_array($va_fields_by_type['preferred_label'])) {
			foreach($va_fields_by_type['preferred_label'] as $vs_placement_code => $vs_f) {
				// check for existing labels to update (or delete)
				$va_preferred_labels = $this->getPreferredLabels(null, false);
				foreach($va_preferred_labels as $vn_item_id => $va_labels_by_locale) {
					foreach($va_labels_by_locale as $vn_locale_id => $va_label_list) {
						foreach($va_label_list as $va_label) {
							if ($vn_label_locale_id = $po_request->getParameter($vs_placement_code.$vs_form_prefix.'_Pref'.'locale_id_'.$va_label['label_id'], pString)) {
							
								if(is_array($va_label_values = $this->getLabelUIValuesFromRequest($po_request, $vs_placement_code.$vs_form_prefix, $va_label['label_id'], true))) {
									
									if ($vb_check_for_dupe_labels && $this->checkForDupeLabel($vn_label_locale_id, $va_label_values)) {
										$this->postError(1125, _t('Value <em>%1</em> is already used and duplicates are not allowed', join("/", $va_label_values)), "BundlableLabelableBaseModelWithAttributes->saveBundlesForScreen()");
										$po_request->addActionErrors($this->errors(), 'preferred_labels');
										continue;
									}
									$vn_label_type_id = $po_request->getParameter($vs_placement_code.$vs_form_prefix.'_Pref'.'type_id_'.$va_label['label_id'], pInteger);
									$this->editLabel($va_label['label_id'], $va_label_values, $vn_label_locale_id, $vn_label_type_id, true);
									if ($this->numErrors()) {
										foreach($this->errors() as $o_e) {
											switch($o_e->getErrorNumber()) {
												case 795:
													// field conflicts
													$po_request->addActionError($o_e, 'preferred_labels');
													break;
												default:
													$po_request->addActionError($o_e, $vs_f);
													break;
											}
										}
									}
								}
							} else {
								if ($po_request->getParameter($vs_placement_code.$vs_form_prefix.'_PrefLabel_'.$va_label['label_id'].'_delete', pString)) {
									// delete
									$this->removeLabel($va_label['label_id']);
								}
							}
						}
					}
				}
				
				// check for new labels to add
				foreach($_REQUEST as $vs_key => $vs_value ) {
					if (!preg_match('/'.$vs_placement_code.$vs_form_prefix.'_Pref'.'locale_id_new_([\d]+)/', $vs_key, $va_matches)) { continue; }
					$vn_c = intval($va_matches[1]);
					if ($vn_new_label_locale_id = $po_request->getParameter($vs_placement_code.$vs_form_prefix.'_Pref'.'locale_id_new_'.$vn_c, pString)) {
						if(is_array($va_label_values = $this->getLabelUIValuesFromRequest($po_request, $vs_placement_code.$vs_form_prefix, 'new_'.$vn_c, true))) {
							if ($vb_check_for_dupe_labels && $this->checkForDupeLabel($vn_new_label_locale_id, $va_label_values)) {
								$this->postError(1125, _t('Value <em>%1</em> is already used and duplicates are not allowed', join("/", $va_label_values)), "BundlableLabelableBaseModelWithAttributes->saveBundlesForScreen()");
								$po_request->addActionErrors($this->errors(), 'preferred_labels');
								$vb_error_inserting_pref_label = true;
								continue;
							}
							$vn_label_type_id = $po_request->getParameter($vs_placement_code.$vs_form_prefix.'_Pref'.'type_id_new_'.$vn_c, pInteger);
							$this->addLabel($va_label_values, $vn_new_label_locale_id, $vn_label_type_id, true);	
							if ($this->numErrors()) {
								$po_request->addActionErrors($this->errors(), $vs_f);
								$vb_error_inserting_pref_label = true;
							}
						}
					}
				}
			}
		}
		
		// Add default label if needed (ie. if the user has failed to set at least one label or if they have deleted all existing labels)
		// This ensures at least one label is present for the record. If no labels are present then the 
		// record may not be found in queries
		if ($vb_error_inserting_pref_label || !$this->addDefaultLabel($vn_new_label_locale_id)) {
			if (!$vb_error_inserting_pref_label) { $po_request->addActionErrors($this->errors(), 'preferred_labels'); }
			
			if ($vb_we_set_transaction) { $this->removeTransaction(false); }
			if ($vb_is_insert) { 
				$this->_FIELD_VALUES[$this->primaryKey()] = null; 											// clear primary key, which doesn't actually exist since we rolled back the transaction
				foreach($va_inserted_attributes_by_element as $vn_element_id => $va_failed_inserts) {		// set attributes as "failed" (but with no error messages) so they stay set
					$this->setFailedAttributeInserts($vn_element_id, $va_failed_inserts);
				}
			}
			return false;
		}
		unset($va_inserted_attributes_by_element);
		
		// save non-preferred labels
		if (isset($va_fields_by_type['nonpreferred_label']) && is_array($va_fields_by_type['nonpreferred_label'])) {
			foreach($va_fields_by_type['nonpreferred_label'] as $vs_placement_code => $vs_f) {
				// check for existing labels to update (or delete)
				$va_nonpreferred_labels = $this->getNonPreferredLabels(null, false);
				foreach($va_nonpreferred_labels as $vn_item_id => $va_labels_by_locale) {
					foreach($va_labels_by_locale as $vn_locale_id => $va_label_list) {
						foreach($va_label_list as $va_label) {
							if ($vn_label_locale_id = $po_request->getParameter($vs_placement_code.$vs_form_prefix.'_NPref'.'locale_id_'.$va_label['label_id'], pString)) {
								if (is_array($va_label_values = $this->getLabelUIValuesFromRequest($po_request, $vs_placement_code.$vs_form_prefix, $va_label['label_id'], false))) {
									$vn_label_type_id = $po_request->getParameter($vs_placement_code.$vs_form_prefix.'_NPref'.'type_id_'.$va_label['label_id'], pInteger);
									$this->editLabel($va_label['label_id'], $va_label_values, $vn_label_locale_id, $vn_label_type_id, false);
									if ($this->numErrors()) {
										foreach($this->errors() as $o_e) {
											switch($o_e->getErrorNumber()) {
												case 795:
													// field conflicts
													$po_request->addActionError($o_e, 'nonpreferred_labels');
													break;
												default:
													$po_request->addActionError($o_e, $vs_f);
													break;
											}
										}
									}
								}
							} else {
								if ($po_request->getParameter($vs_placement_code.$vs_form_prefix.'_NPrefLabel_'.$va_label['label_id'].'_delete', pString)) {
									// delete
									$this->removeLabel($va_label['label_id']);
								}
							}
						}
					}
				}
				
				// check for new labels to add
				foreach($_REQUEST as $vs_key => $vs_value ) {
					if (!preg_match('/'.$vs_placement_code.$vs_form_prefix.'_NPref'.'locale_id_new_([\d]+)/', $vs_key, $va_matches)) { continue; }
					$vn_c = intval($va_matches[1]);
					if ($vn_new_label_locale_id = $po_request->getParameter($vs_placement_code.$vs_form_prefix.'_NPref'.'locale_id_new_'.$vn_c, pString)) {
						if (is_array($va_label_values = $this->getLabelUIValuesFromRequest($po_request, $vs_placement_code.$vs_form_prefix, 'new_'.$vn_c, false))) {
							$vn_new_label_type_id = $po_request->getParameter($vs_placement_code.$vs_form_prefix.'_NPref'.'type_id_new_'.$vn_c, pInteger);
							$this->addLabel($va_label_values, $vn_new_label_locale_id, $vn_new_label_type_id, false);	
							
							if ($this->numErrors()) {
								$po_request->addActionErrors($this->errors(), $vs_f);
							}
						}
					}
				}
			}
		}
		
		
		// save data in related tables
		if (isset($va_fields_by_type['related_table']) && is_array($va_fields_by_type['related_table'])) {
			foreach($va_fields_by_type['related_table'] as $vs_placement_code => $vs_f) {
				$vn_table_num = $this->_DATAMODEL->getTableNum($vs_f);
				$vs_prefix_stub = $vs_placement_code.$vs_form_prefix.'_'.$vs_f.'_';
				
				switch($vs_f) {
					# -------------------------------------
					case 'ca_object_representations':
						// check for existing representations to update (or delete)
						
						$vb_allow_fetching_of_urls = (bool)$this->_CONFIG->get('allow_fetching_of_media_from_remote_urls');
						$va_rep_ids_sorted = $va_rep_sort_order = explode(';',$po_request->getParameter($vs_prefix_stub.'ObjectRepresentationBundleList', pString));
						sort($va_rep_ids_sorted, SORT_NUMERIC);
						
						
						$va_reps = $this->getRepresentations();
						
						if (is_array($va_reps)) {
							foreach($va_reps as $va_rep) {
								$this->clearErrors();
								if (($vn_status = $po_request->getParameter($vs_prefix_stub.'status_'.$va_rep['representation_id'], pInteger)) != '') {
									if ($vb_allow_fetching_of_urls && ($vs_path = $_REQUEST[$vs_prefix_stub.'media_url_'.$va_rep['representation_id']])) {
										$va_tmp = explode('/', $vs_path);
										$vs_original_name = array_pop($va_tmp);
									} else {
										$vs_path = $_FILES[$vs_prefix_stub.'media_'.$va_rep['representation_id']]['tmp_name'];
										$vs_original_name = $_FILES[$vs_prefix_stub.'media_'.$va_rep['representation_id']]['name'];
									}
									
									$vn_locale_id = $po_request->getParameter($vs_prefix_stub.'locale_id_'.$va_rep['representation_id'], pInteger);
									$vn_access = $po_request->getParameter($vs_prefix_stub.'access_'.$va_rep['representation_id'], pInteger);
									$vn_is_primary = $po_request->getParameter($vs_prefix_stub.'is_primary_'.$va_rep['representation_id'], pInteger);
									
									$vn_rank = null;
									if (($vn_rank_index = array_search($va_rep['representation_id'], $va_rep_sort_order)) !== false) {
										$vn_rank = $va_rep_ids_sorted[$vn_rank_index];
									}
									
									$this->editRepresentation($va_rep['representation_id'], $vs_path, $vn_locale_id, $vn_status, $vn_access, $vn_is_primary, array(), array('original_filename' => $vs_original_name, 'rank' => $vn_rank));
									if ($this->numErrors()) {
										//$po_request->addActionErrors($this->errors(), $vs_f, $va_rep['representation_id']);
										foreach($this->errors() as $o_e) {
											switch($o_e->getErrorNumber()) {
												case 795:
													// field conflicts
													$po_request->addActionError($o_e, $vs_f, $va_rep['representation_id']);
													break;
												default:
													$po_request->addActionError($o_e, $vs_f, $va_rep['representation_id']);
													break;
											}
										}
									}
									
								} else {
									// is it a delete key?
									$this->clearErrors();
									if (($po_request->getParameter($vs_prefix_stub.$va_rep['representation_id'].'_delete', pInteger)) > 0) {
										// delete!
										$this->removeRepresentation($va_rep['representation_id']);
										if ($this->numErrors()) {
											$po_request->addActionErrors($this->errors(), $vs_f, $va_rep['representation_id']);
										}
									}
								}
							}
						}
						
						// check for new representations to add 
						foreach($_FILES as $vs_key => $vs_value) {
							$this->clearErrors();
							if (!preg_match('/^'.$vs_prefix_stub.'media_new_([\d]+)$/', $vs_key, $va_matches)) { continue; }
							if ($vb_allow_fetching_of_urls && ($vs_path = $_REQUEST[$vs_prefix_stub.'media_url_new_'.$va_matches[1]])) {
								$va_tmp = explode('/', $vs_path);
								$vs_original_name = array_pop($va_tmp);
							} else {
								$vs_path = $_FILES[$vs_prefix_stub.'media_new_'.$va_matches[1]]['tmp_name'];
								$vs_original_name = $_FILES[$vs_prefix_stub.'media_new_'.$va_matches[1]]['name'];
							}
							if (!$vs_path) { continue; }
							
							$vn_locale_id = $po_request->getParameter($vs_prefix_stub.'locale_id_new_'.$va_matches[1], pInteger);
							$vn_type_id = $po_request->getParameter($vs_prefix_stub.'type_id_new_'.$va_matches[1], pInteger);
							$vn_status = $po_request->getParameter($vs_prefix_stub.'status_new_'.$va_matches[1], pInteger);
							$vn_access = $po_request->getParameter($vs_prefix_stub.'access_new_'.$va_matches[1], pInteger);
							$vn_is_primary = $po_request->getParameter($vs_prefix_stub.'is_primary_new_'.$va_matches[1], pInteger);
							$this->addRepresentation($vs_path, $vn_type_id, $vn_locale_id, $vn_status, $vn_access, $vn_is_primary, array(), array('original_filename' => $vs_original_name));
							
							if ($this->numErrors()) {
								$po_request->addActionErrors($this->errors(), $vs_f, 'new_'.$va_matches[1]);
							}
						}
						break;
					# -------------------------------------
					case 'ca_entities':
					case 'ca_places':
					case 'ca_objects':
					case 'ca_collections':
					case 'ca_occurrences':
					case 'ca_list_items':
					case 'ca_object_lots':
					case 'ca_storage_locations':
					case 'ca_loans':
					case 'ca_movements':
					case 'ca_tour_stops':
						$this->_processRelated($po_request, $vs_f, $vs_placement_code.$vs_form_prefix);
						break;
					# -------------------------------------
					case 'ca_representation_annotations':
						$this->_processRepresentationAnnotations($po_request, $vs_form_prefix, $vs_placement_code);
						break;
					# -------------------------------------
				}
			}	
		}
		
		
		// save data for "specials"
		if (isset($va_fields_by_type['special']) && is_array($va_fields_by_type['special'])) {
			foreach($va_fields_by_type['special'] as $vs_placement_code => $vs_f) {
				switch($vs_f) {
					# -------------------------------------
					// This bundle is only available when editing objects of type ca_representation_annotations
					case 'ca_representation_annotation_properties':
						foreach($this->getPropertyList() as $vs_property) {
							$this->setPropertyValue($vs_property, $po_request->getParameter($vs_property, pString));
						}
						if (!$this->validatePropertyValues()) {
							$po_request->addActionErrors($this->errors(), 'ca_representation_annotation_properties', 'general');
						}
						break;
					# -------------------------------------
					// This bundle is only available for types which support set membership
					case 'ca_sets':
						// check for existing labels to delete (no updating supported)
						require_once(__CA_MODELS_DIR__.'/ca_sets.php');
						require_once(__CA_MODELS_DIR__.'/ca_set_items.php');
	
						$t_set = new ca_sets();
						$va_sets = caExtractValuesByUserLocale($t_set->getSetsForItem($this->tableNum(), $this->getPrimaryKey(), array('user_id' => $po_request->getUserID()))); 
	
						foreach($va_sets as $vn_set_id => $va_set_info) {
							$vn_item_id = $va_set_info['item_id'];
							
							if ($po_request->getParameter($vs_form_prefix.'_ca_sets_set_id_'.$vn_item_id.'_delete', pString)) {
								// delete
								$t_set->load($va_set_info['set_id']);
								$t_set->removeItem($this->getPrimaryKey(), $po_request->getUserID());	// remove *all* instances of the item in the set, not just the specified id
								if ($t_set->numErrors()) {
									$po_request->addActionErrors($t_set->errors(), $vs_f);
								}
							}
						}
						
						foreach($_REQUEST as $vs_key => $vs_value) {
							if (!preg_match('/'.$vs_form_prefix.'_ca_sets_set_id_new_([\d]+)/', $vs_key, $va_matches)) { continue; }
							$vn_c = intval($va_matches[1]);
							if ($vn_new_set_id = $po_request->getParameter($vs_form_prefix.'_ca_sets_set_id_new_'.$vn_c, pString)) {
								$t_set->load($vn_new_set_id);
								$t_set->addItem($this->getPrimaryKey(), null, $po_request->getUserID());
								if ($t_set->numErrors()) {
									$po_request->addActionErrors($t_set->errors(), $vs_f);
								}
							}
						}
						break;
					# -------------------------------------
					// This bundle is only available for types which support set membership
					case 'ca_set_items':
						// check for existing labels to delete (no updating supported)
						require_once(__CA_MODELS_DIR__.'/ca_sets.php');
						require_once(__CA_MODELS_DIR__.'/ca_set_items.php');
						
						$va_row_ids = explode(';', $po_request->getParameter($vs_form_prefix.'_ca_set_itemssetRowIDList', pString));
						$this->reorderItems($va_row_ids, array('user_id' => $po_request->getUserID()));
						break;
					# -------------------------------------
					// This bundle is only available for ca_search_forms 
					case 'ca_search_form_elements':
						// save settings
						$va_settings = $this->getAvailableSettings();
						foreach($va_settings as $vs_setting => $va_setting_info) {
							if(isset($_REQUEST['setting_'.$vs_setting])) {
								$vs_setting_val = $po_request->getParameter('setting_'.$vs_setting, pString);
								$this->setSetting($vs_setting, $vs_setting_val);
								$this->update();
							}
						}
						break;
					# -------------------------------------
					// This bundle is only available for ca_bundle_displays 
					case 'ca_bundle_display_placements':
						$this->savePlacementsFromHTMLForm($po_request, $vs_form_prefix);
						break;
					# -------------------------------------
					// This bundle is only available for ca_search_forms 
					case 'ca_search_form_placements':
						$this->savePlacementsFromHTMLForm($po_request, $vs_form_prefix);
						break;
					# -------------------------------------
					// This bundle is only available for ca_editor_ui
					case 'ca_editor_ui_screens':
						global $g_ui_locale_id;
						require_once(__CA_MODELS_DIR__.'/ca_editor_ui_screens.php');
						$va_screen_ids = explode(';', $po_request->getParameter($vs_form_prefix.'_ca_editor_ui_screens_ScreenBundleList', pString));
						
						foreach($_REQUEST as $vs_key => $vs_val) {
							if (is_array($vs_val)) { continue; }
							if (!($vs_val = trim($vs_val))) { continue; }
							if (preg_match("!^{$vs_form_prefix}_ca_editor_ui_screens_name_new_([\d]+)$!", $vs_key, $va_matches)) {
								if (!($t_screen = $this->addScreen($vs_val, $g_ui_locale_id, 'screen_'.$this->getPrimaryKey().'_'.$va_matches[1]))) { break; }
								
								if ($vn_fkey = array_search("new_".$va_matches[1], $va_screen_ids)) {
									$va_screen_ids[$vn_fkey] = $t_screen->getPrimaryKey();
								} else {
									$va_screen_ids[] = $t_screen->getPrimaryKey();
								}
								continue;
							}
							
							if (preg_match("!^{$vs_form_prefix}_ca_editor_ui_screens_([\d]+)_delete$!", $vs_key, $va_matches)) {
								$this->removeScreen($va_matches[1]);
								if ($vn_fkey = array_search($va_matches[1], $va_screen_ids)) { unset($va_screen_ids[$vn_fkey]); }
							}
						}
						$this->reorderScreens($va_screen_ids);
						break;
					# -------------------------------------
					// This bundle is only available for ca_editor_ui_screens
					case 'ca_editor_ui_bundle_placements':
						$this->savePlacementsFromHTMLForm($po_request, $vs_form_prefix);
						break;
					# -------------------------------------
					// This bundle is only available for ca_editor_uis
					case 'ca_editor_ui_type_restrictions':
						$this->saveTypeRestrictionsFromHTMLForm($po_request, $vs_form_prefix);
						break;
					# -------------------------------------
					// This bundle is only available for ca_editor_ui_screens
					case 'ca_editor_ui_screen_type_restrictions':
						$this->saveTypeRestrictionsFromHTMLForm($po_request, $vs_form_prefix);
						break;
					# -------------------------------------
					// This bundle is only available for ca_tours
					case 'ca_tour_stops_list':
						global $g_ui_locale_id;
						require_once(__CA_MODELS_DIR__.'/ca_tour_stops.php');
						$va_stop_ids = explode(';', $po_request->getParameter($vs_form_prefix.'_ca_tour_stops_list_StopBundleList', pString));
						
						foreach($_REQUEST as $vs_key => $vs_val) {
							if (!($vs_val = trim($vs_val))) { continue; }
							if (preg_match("!^{$vs_form_prefix}_ca_tour_stops_list_name_new_([\d]+)$!", $vs_key, $va_matches)) {
								$vn_type_id = $_REQUEST["{$vs_form_prefix}_ca_tour_stops_list_type_id_new_".$va_matches[1]];
								if (!($t_stop = $this->addStop($vs_val, $vn_type_id, $g_ui_locale_id, mb_substr(preg_replace('![^A-Za-z0-9_]+!', '_', $vs_val),0, 255)))) { break; }
								
								if ($vn_fkey = array_search("new_".$va_matches[1], $va_stop_ids)) {
									$va_stop_ids[$vn_fkey] = $t_stop->getPrimaryKey();
								} else {
									$va_stop_ids[] = $t_stop->getPrimaryKey();
								}
								continue;
							}
							
							if (preg_match("!^{$vs_form_prefix}_ca_tour_stops_list_([\d]+)_delete$!", $vs_key, $va_matches)) {
								$this->removeStop($va_matches[1]);
								if ($vn_fkey = array_search($va_matches[1], $va_stop_ids)) { unset($va_stop_ids[$vn_fkey]); }
							}
						}
						$this->reorderStops($va_stop_ids);
						break;
					# -------------------------------------
					// This bundle is only available for ca_bundle_mappings
					case 'ca_bundle_mapping_groups':
						global $g_ui_locale_id;
						require_once(__CA_MODELS_DIR__.'/ca_bundle_mapping_groups.php');
						$va_group_ids = explode(';', $po_request->getParameter($vs_form_prefix.'_ca_bundle_mapping_groups_GroupBundleList', pString));
						//print_R($_REQUEST);
						foreach($_REQUEST as $vs_key => $vs_val) {
							if (is_array($vs_val) || !($vs_val = trim($vs_val))) { continue; }
							if (preg_match("!^{$vs_form_prefix}_ca_bundle_mapping_groups_name_new_([\d]+)$!", $vs_key, $va_matches)) {
								if (!($t_group = $this->addGroup($vs_val, $vs_val, '', $g_ui_locale_id))) { break; }
								
								if ($vn_fkey = array_search("new_".$va_matches[1], $va_group_ids)) {
									$va_group_ids[$vn_fkey] = $t_group->getPrimaryKey();
								} else {
									$va_group_ids[] = $t_group->getPrimaryKey();
								}
								continue;
							}
							
							if (preg_match("!^{$vs_form_prefix}_ca_bundle_mapping_groups_([\d]+)_delete$!", $vs_key, $va_matches)) {
								$this->removeGroup($va_matches[1]);
								if ($vn_fkey = array_search($va_matches[1], $va_group_ids)) { unset($va_group_ids[$vn_fkey]); }
							}
						}
						$this->reorderGroups($va_group_ids);
						break;
					# -------------------------------------
					// This bundle is only available for ca_bundle_mapping_groups
					case 'ca_bundle_mapping_rules':
						require_once(__CA_MODELS_DIR__.'/ca_bundle_mapping_rules.php');
						$va_rule_ids = explode(';', $po_request->getParameter($vs_form_prefix.'_ca_bundle_mapping_rules_RuleBundleList', pString));
						
						foreach($_REQUEST as $vs_key => $vs_val) {
							if (!$vs_val) { continue; }
							//MappingGroupEditorForm_ca_bundle_mapping_rules_ca_path_suffix_new_0
							if (preg_match("!^{$vs_form_prefix}_ca_bundle_mapping_rules_ca_path_suffix_new_([\d]+)$!", $vs_key, $va_matches)) {
								
								$vs_ca_path_suffix = $po_request->getParameter("{$vs_form_prefix}_ca_bundle_mapping_rules_ca_path_suffix_new_".$va_matches[1], pString);
								$vs_external_path_suffix = $po_request->getParameter("{$vs_form_prefix}_ca_bundle_mapping_rules_external_path_suffix_new_".$va_matches[1], pString);
								if (!($t_rule = $this->addRule($vs_ca_path_suffix, $vs_external_path_suffix, '', array()))) { break; }
								
								if ($vn_fkey = array_search("new_".$va_matches[1], $va_rule_ids)) {
									$va_rule_ids[$vn_fkey] = $t_rule->getPrimaryKey();
								} else {
									$va_rule_ids[] = $t_rule->getPrimaryKey();
								}
								
								// save settings
								foreach($t_rule->getAvailableSettings() as $vs_setting => $va_setting_info) {
									$vs_setting_value = $po_request->getParameter("{$vs_form_prefix}_ca_bundle_mapping_rules_setting_new_".$va_matches[1]."_{$vs_setting}", pString);
									$t_rule->setSetting($vs_setting, $vs_setting_value);
								}
								$t_rule->update();
								continue;
							}
							
							if (preg_match("!^{$vs_form_prefix}_ca_bundle_mapping_rules_ca_path_suffix_([\d]+)$!", $vs_key, $va_matches)) {
								$t_rule = new ca_bundle_mapping_rules($va_matches[1]);
								$t_rule->setMode(ACCESS_WRITE);
								$t_rule->set('ca_path_suffix', $po_request->getParameter($vs_key, pString));
								$t_rule->set('external_path_suffix', $po_request->getParameter("{$vs_form_prefix}_ca_bundle_mapping_rules_external_path_suffix_".$va_matches[1], pString));
								
								// save settings
								foreach($t_rule->getAvailableSettings() as $vs_setting => $va_setting_info) {
									$vs_setting_value = $po_request->getParameter("{$vs_form_prefix}_ca_bundle_mapping_rules_setting_".$t_rule->getPrimaryKey()."_{$vs_setting}", pString);
									$t_rule->setSetting($vs_setting, $vs_setting_value);
								}
								
								$t_rule->update();
								continue;
							}
							
							if (preg_match("!^{$vs_form_prefix}_ca_bundle_mapping_rules_([\d]+)_delete$!", $vs_key, $va_matches)) {
								$this->removeRule($va_matches[1]);
								if ($vn_fkey = array_search($va_matches[1], $va_rule_ids)) { unset($va_rule_ids[$vn_fkey]); }
							}
						}
						$this->reorderRules($va_rule_ids);
						break;
					# -------------------------------------
					case 'ca_user_groups':
						if (!$po_request->user->canDoAction('is_administrator') && ($po_request->getUserID() != $this->get('user_id'))) { break; }	// don't save if user is not owner
						require_once(__CA_MODELS_DIR__.'/ca_user_groups.php');
	
						$va_groups = $po_request->user->getGroupList($po_request->getUserID());
						
						$va_groups_to_set = $va_group_effective_dates = array();
						foreach($_REQUEST as $vs_key => $vs_val) { 
							if (preg_match("!^{$vs_form_prefix}_ca_user_groups_id(.*)$!", $vs_key, $va_matches)) {
								$vs_effective_date = $po_request->getParameter($vs_form_prefix.'_ca_user_groups_effective_date_'.$va_matches[1], pString);
								$vn_group_id = (int)$po_request->getParameter($vs_form_prefix.'_ca_user_groups_id'.$va_matches[1], pInteger);
								$vn_access = $po_request->getParameter($vs_form_prefix.'_ca_user_groups_access_'.$va_matches[1], pInteger);
								if ($vn_access > 0) {
									$va_groups_to_set[$vn_group_id] = $vn_access;
									$va_group_effective_dates[$vn_group_id] = $vs_effective_date;
								}
							}
						}
												
						$this->setUserGroups($va_groups_to_set, $va_group_effective_dates, array('user_id' => $po_request->getUserID()));
						
						break;
					# -------------------------------------
					case 'ca_users':
						if (!$po_request->user->canDoAction('is_administrator') && ($po_request->getUserID() != $this->get('user_id'))) { break; }	// don't save if user is not owner
						require_once(__CA_MODELS_DIR__.'/ca_users.php');
	
						$va_users = $po_request->user->getUserList($po_request->getUserID());
						
						$va_users_to_set = $va_user_effective_dates = array();
						foreach($_REQUEST as $vs_key => $vs_val) { 
							if (preg_match("!^{$vs_form_prefix}_ca_users_id(.*)$!", $vs_key, $va_matches)) {
								$vs_effective_date = $po_request->getParameter($vs_form_prefix.'_ca_users_effective_date_'.$va_matches[1], pString);
								$vn_user_id = (int)$po_request->getParameter($vs_form_prefix.'_ca_users_id'.$va_matches[1], pInteger);
								$vn_access = $po_request->getParameter($vs_form_prefix.'_ca_users_access_'.$va_matches[1], pInteger);
								if ($vn_access > 0) {
									$va_users_to_set[$vn_user_id] = $vn_access;
									$va_user_effective_dates[$vn_user_id] = $vs_effective_date;
								}
							}
						}
						
						$this->setUsers($va_users_to_set, $va_user_effective_dates);
						
						break;
					# -------------------------------------
					case 'settings':
						$this->setSettingsFromHTMLForm($po_request);
						break;
					# -------------------------------
					// This bundle is only available when editing objects of type ca_object_representations
					case 'ca_object_representations_media_display':
						$va_versions_to_process = null;
						
						if ($vb_use_options = (bool)$po_request->getParameter($vs_placement_code.$vs_form_prefix.'_media_display_derivative_options_selector', pInteger)) {
							// update only specified versions
							$va_versions_to_process =  $po_request->getParameter($vs_placement_code.$vs_form_prefix.'_media_display_set_versions', pArray);
						} 
					
						if (!is_array($va_versions_to_process) || !sizeof($va_versions_to_process)) {
							$va_versions_to_process = array('_all');	
						}
						
						if ($vb_use_options && ($po_request->getParameter($vs_placement_code.$vs_form_prefix.'_media_display_derivative_options_mode', pString) == 'timecode')) {
							// timecode
							if (!(string)($vs_timecode = $po_request->getParameter($vs_placement_code.$vs_form_prefix.'_media_display_derivative_options_mode_timecode_value', pString))) {
								$vs_timecode = "1s";
							}
							//
							$o_media = new Media();
							if ($o_media->read($this->getMediaPath('media', 'original'))) {
								$va_files = $o_media->writePreviews(array('force' => true, 'outputDirectory' => $this->_CONFIG->get("taskqueue_tmp_directory"), 'minNumberOfFrames' => 1, 'maxNumberOfFrames' => 1, 'startAtTime' => $vs_timecode, 'endAtTime' => $vs_timecode, 'width' => 720, 'height' => 540));
						
								if(sizeof($va_files)) { 
									$this->set('media', array_shift($va_files));
								}
							}
							
						} else {
							if ($vb_use_options && ($po_request->getParameter($vs_placement_code.$vs_form_prefix.'_media_display_derivative_options_mode', pString) == 'page')) {
								if (!(int)($vn_page = $po_request->getParameter($vs_placement_code.$vs_form_prefix.'_media_display_derivative_options_mode_page_value', pInteger))) {
									$vn_page = 1;
								}
								//
								$o_media = new Media();
								if ($o_media->read($this->getMediaPath('media', 'original'))) {
									$va_files = $o_media->writePreviews(array('force' => true, 'outputDirectory' => $this->_CONFIG->get("taskqueue_tmp_directory"), 'numberOfPages' => 1, 'startAtPage' => $vn_page, 'width' => 2048, 'height' => 2048));
							
									if(sizeof($va_files)) { 
										$this->set('media', array_shift($va_files));
									}
								}
							} else {
								// process file as new upload
								$vs_key = $vs_placement_code.$vs_form_prefix.'_media_display_url';
								if (($vs_media_url = trim($po_request->getParameter($vs_key, pString))) && isURL($vs_media_url)) {
									$this->set('media', $vs_media_url);
								} else {
									$vs_key = $vs_placement_code.$vs_form_prefix.'_media_display_media';
									if (isset($_FILES[$vs_key])) {
										$this->set('media', $_FILES[$vs_key]['tmp_name'], array('original_filename' => $_FILES[$vs_key]['name']));
									}
								}
							}
						}
						
						if ($this->changed('media')) {
							$this->update(($vs_version != '_all') ? array('update_only_media_versions' => $va_versions_to_process) : array());
							if ($this->numErrors()) {
								$po_request->addActionErrors($this->errors(), 'ca_object_representations_media_display', 'general');
							}
						}
						
						break;
					# -------------------------------------
				}
			}
		}
		
		BaseModel::unsetChangeLogUnitID();
		if ($vb_we_set_transaction) { $this->removeTransaction(true); }
		return true;
	}
 	# ------------------------------------------------------
 	/**
 	 *
 	 */
 	private function _processRelated($po_request, $ps_bundlename, $ps_form_prefix) {
 		$va_rel_ids_sorted = $va_rel_sort_order = explode(';',$po_request->getParameter($ps_form_prefix.'_'.$ps_bundlename.'BundleList', pString));
		sort($va_rel_ids_sorted, SORT_NUMERIC);
						
 		$va_rel_items = $this->getRelatedItems($ps_bundlename);
 		
		foreach($va_rel_items as $va_rel_item) {
			$vs_key = $va_rel_item['_key'];
			
			$vn_rank = null;
			if (($vn_rank_index = array_search($va_rel_item['relation_id'], $va_rel_sort_order)) !== false) {
				$vn_rank = $va_rel_ids_sorted[$vn_rank_index];
			}
			
			$this->clearErrors();
			$vn_id = $po_request->getParameter($ps_form_prefix.'_'.$ps_bundlename.'_id'.$va_rel_item[$vs_key], pString);
			if ($vn_id) {
				$vn_type_id = $po_request->getParameter($ps_form_prefix.'_'.$ps_bundlename.'_type_id'.$va_rel_item[$vs_key], pString);
				$vs_direction = null;
				if (sizeof($va_tmp = explode('_', $vn_type_id)) == 2) {
					$vn_type_id = (int)$va_tmp[1];
					$vs_direction = $va_tmp[0];
				}
				
				$this->editRelationship($ps_bundlename, $va_rel_item[$vs_key], $vn_id, $vn_type_id, null, null, $vs_direction, $vn_rank);	
					
				if ($this->numErrors()) {
					$po_request->addActionErrors($this->errors(), $vs_f);
				}
			} else {
				// is it a delete key?
				$this->clearErrors();
				if (($po_request->getParameter($ps_form_prefix.'_'.$ps_bundlename.'_'.$va_rel_item[$vs_key].'_delete', pInteger)) > 0) {
					// delete!
					$this->removeRelationship($ps_bundlename, $va_rel_item[$vs_key]);
					if ($this->numErrors()) {
						$po_request->addActionErrors($this->errors(), $vs_f, $va_rel_item[$vs_key]);
					}
				}
			}
		}
 		
 		// check for new relations to add
 		foreach($_REQUEST as $vs_key => $vs_value ) {
			if (!preg_match('/^'.$ps_form_prefix.'_'.$ps_bundlename.'_idnew_([\d]+)/', $vs_key, $va_matches)) { continue; }
			$vn_c = intval($va_matches[1]);
			if ($vn_new_id = $po_request->getParameter($ps_form_prefix.'_'.$ps_bundlename.'_idnew_'.$vn_c, pString)) {
				$vn_new_type_id = $po_request->getParameter($ps_form_prefix.'_'.$ps_bundlename.'_type_idnew_'.$vn_c, pString);
				
				$vs_direction = null;
				if (sizeof($va_tmp = explode('_', $vn_new_type_id)) == 2) {
					$vn_new_type_id = (int)$va_tmp[1];
					$vs_direction = $va_tmp[0];
				}
				
				$this->addRelationship($ps_bundlename, $vn_new_id, (int)$vn_new_type_id, null, null, $vs_direction);	
				if ($this->numErrors()) {
					$po_request->addActionErrors($this->errors(), $vs_f);
				}
			}
		}
		return true;
 	}
 	# ------------------------------------------------------
 	/**
 	 * Returns list of items in the specified table related to the currently loaded row.
 	 * 
 	 * @param $pm_rel_table_name_or_num - the table name or table number of the item type you want to get a list of (eg. if you are calling this on an ca_objects instance passing 'ca_entities' here will get you a list of entities related to the object)
 	 * @param $pa_options - array of options. Supported options are:
 	 *
 	 * 		restrict_to_type = restricts returned items to those of the specified type; only supports a single type which can be specified as a list item_code or item_id
 	 *		restrictToType = synonym for restrict_to_type
 	 *		restrict_to_types = restricts returned items to those of the specified types; pass an array of list item_codes or item_ids
 	 *		restrictToTypes = synonym for restrict_to_types
 	 *		dont_include_subtypes_in_type_restriction = if set subtypes are not included when enforcing restrict_to_types. Note that restrict_to_relationship_types always includes subtypes in its restriction.
 	 *		dontIncludeSubtypesInTypeRestriction = synonym for dont_include_subtypes_in_type_restriction
 	 *		restrict_to_relationship_types = restricts returned items to those related to the current row by the specified relationship type(s). You can pass either an array of types or a single type. The types can be relationship type_code's or type_id's.
 	 *		restrictToRelationshipTypes = synonym for restrict_to_relationship_types
 	 *
 	 *		exclude_relationship_types = omits any items related to the current row with any of the specified types from the returned set of ids. You can pass either an array of types or a single type. The types can be relationship type_code's or type_id's.
 	 *		excludeRelationshipTypes = synonym for exclude_relationship_types
 	 * 		exclude_type = excludes returned items of the specified type; only supports a single type which can be specified as a list item_code or item_id
 	 *		excludeType = synonym for exclude_type
 	 *		exclude_types = omits any items related to the current row that are of any of the specified types from the returned set of ids. You can pass either an array of types or a single type. The types can be type_code's or type_id's.
 	 *		excludeTypes = synonym for exclude_types
 	 *
 	 *		fields = array of fields (in table.fieldname format) to include in returned data
 	 *		return_non_preferred_labels = if set to true, non-preferred labels are included in returned data
 	 *		returnNonPreferredLabels = synonym for return_non_preferred_labels
 	 *		checkAccess = array of access values to filter results by; if defined only items with the specified access code(s) are returned
 	 *		return_labels_as_array = if set to true then all labels associated with row are returned in an array, otherwise only a text value in the current locale is returned; default is false - return single label in current locale
 	 *		returnLabelsAsArray = synonym for return_labels_as_array
 	 * 		row_ids = array of primary key values to use when fetching related items; if omitted or set to a null value the 'row_id' option (single value) will be used; if row_id is also not set then the currently loaded primary key value will be used
 	 *		row_id = primary key value to use when fetching related items; if omitted or set to a false value (eg. null, false, 0) then the currently loaded primary key value is used [default]
 	 *		start = item to start return set at; first item is numbered zero; default is 0
 	 *		limit = number of items to limit return set to; default is 1000
 	 *		sort = optional array of bundles to sort returned values on. Currently only supported when getting related values via simple related <table_name> and <table_name>.related invokations. Eg. from a ca_objects results you can use the 'sort' option got get('ca_entities'), get('ca_entities.related') or get('ca_objects.related'). The bundle specifiers are fields with or without tablename. Only those fields returned for the related tables (intrinsics, label fields and attributes) are sortable.
 	 *		showDeleted = if set to true, related items that have been deleted are returned. Default is false.
	 *		where = optional array of fields and field values to filter returned values on. The fields must be intrinsic and in the same table as the field being "get()'ed" Can be used to filter returned values from primary and related tables. This option can be useful when you want to fetch certain values from a related table. For example, you want to get the relationship source_info values, but only for relationships going to a specific related record. Note that multiple fields/values are effectively AND'ed together - all must match for a row to be returned - and that only equivalence is supported (eg. field equals value).
 	 * @return array - list of related items
 	 */
	 public function getRelatedItems($pm_rel_table_name_or_num, $pa_options=null) {
	 	// convert options
	 	if(isset($pa_options['restrictToType']) && (!isset($pa_options['restrict_to_type']) || !$pa_options['restrict_to_type'])) { $pa_options['restrict_to_type'] = $pa_options['restrictToType']; }
	 	if(isset($pa_options['restrictToTypes']) && (!isset($pa_options['restrict_to_types']) || !$pa_options['restrict_to_types'])) { $pa_options['restrict_to_types'] = $pa_options['restrictToTypes']; }
	 	if(isset($pa_options['restrictToRelationshipTypes']) && (!isset($pa_options['restrict_to_relationship_types']) || !$pa_options['restrict_to_relationship_types'])) { $pa_options['restrict_to_relationship_types'] = $pa_options['restrictToRelationshipTypes']; }
	 	if(isset($pa_options['excludeType']) && (!isset($pa_options['exclude_type']) || !$pa_options['exclude_type'])) { $pa_options['exclude_type'] = $pa_options['excludeType']; }
	 	if(isset($pa_options['excludeTypes']) && (!isset($pa_options['exclude_types']) || !$pa_options['exclude_types'])) { $pa_options['exclude_types'] = $pa_options['excludeTypes']; }
	 	if(isset($pa_options['excludeRelationshipTypes']) && (!isset($pa_options['exclude_relationship_types']) || !$pa_options['exclude_relationship_types'])) { $pa_options['exclude_relationship_types'] = $pa_options['excludeRelationshipTypes']; }
	 	if(isset($pa_options['dontIncludeSubtypesInTypeRestriction']) && (!isset($pa_options['dont_include_subtypes_in_type_restriction']) || !$pa_options['dont_include_subtypes_in_type_restriction'])) { $pa_options['dont_include_subtypes_in_type_restriction'] = $pa_options['dontIncludeSubtypesInTypeRestriction']; }
	 	if(isset($pa_options['returnNonPreferredLabels']) && (!isset($pa_options['return_non_preferred_labels']) || !$pa_options['return_non_preferred_labels'])) { $pa_options['return_non_preferred_labels'] = $pa_options['returnNonPreferredLabels']; }
	 	if(isset($pa_options['returnLabelsAsArray']) && (!isset($pa_options['return_labels_as_array']) || !$pa_options['return_labels_as_array'])) { $pa_options['return_labels_as_array'] = $pa_options['returnLabelsAsArray']; }
	 
		$o_db = $this->getDb();
		$o_tep = new TimeExpressionParser();
		$vb_uses_effective_dates = false;
		
		$va_get_where = 			(isset($pa_options['where']) && is_array($pa_options['where']) && sizeof($pa_options['where'])) ? $pa_options['where'] : null;
		
		$va_row_ids = (isset($pa_options['row_ids']) && is_array($pa_options['row_ids'])) ? $pa_options['row_ids'] : null;
		$vn_row_id = (isset($pa_options['row_id']) && $pa_options['row_id']) ? $pa_options['row_id'] : $this->getPrimaryKey();
		
		if(isset($pa_options['sort']) && !is_array($pa_options['sort'])) { $pa_options['sort'] = array($pa_options['sort']); }
		$va_sort_fields = (isset($pa_options['sort']) && is_array($pa_options['sort'])) ? $pa_options['sort'] : null;
		
		if (!$va_row_ids && ($vn_row_id > 0)) {
			$va_row_ids = array($vn_row_id);
		}
		
		if (!$va_row_ids || !is_array($va_row_ids) || !sizeof($va_row_ids)) { return array(); }
		
		$vb_return_labels_as_array = (isset($pa_options['return_labels_as_array']) && $pa_options['return_labels_as_array']) ? true : false;
		$vn_limit = (isset($pa_options['limit']) && ((int)$pa_options['limit'] > 0)) ? (int)$pa_options['limit'] : 1000;
		$vn_start = (isset($pa_options['start']) && ((int)$pa_options['start'] > 0)) ? (int)$pa_options['start'] : 0;
              
		if (is_numeric($pm_rel_table_name_or_num)) {
			$vs_related_table_name = $this->getAppDataModel()->getTableName($pm_rel_table_name_or_num);
		} else {
			$vs_related_table_name = $pm_rel_table_name_or_num;
		}
		
		if (!is_array($pa_options)) { $pa_options = array(); }

		switch(sizeof($va_path = array_keys($this->getAppDatamodel()->getPath($this->tableName(), $vs_related_table_name)))) {
			case 3:
				$t_item_rel = $this->getAppDatamodel()->getTableInstance($va_path[1]);
				$t_rel_item = $this->getAppDatamodel()->getTableInstance($va_path[2]);
				$vs_key = 'relation_id';
				break;
			case 2:
				$t_item_rel = null;
				$t_rel_item = $this->getAppDatamodel()->getTableInstance($va_path[1]);
				$vs_key = $t_rel_item->primaryKey();
				break;
			default:
				// bad related table
				return null;
				break;
		}
		
		// check for self relationship
		$vb_self_relationship = false;
		if($this->tableName() == $vs_related_table_name) {
			$vb_self_relationship = true;
			$t_rel_item = $this->getAppDatamodel()->getTableInstance($va_path[0]);
			$t_item_rel = $this->getAppDatamodel()->getTableInstance($va_path[1]);
		}
		
		$va_wheres = array();
		$va_selects = array();

		// TODO: get these field names from models
		if ($t_item_rel) {
			//define table names
			$vs_linking_table = $t_item_rel->tableName();
			$vs_related_table = $t_rel_item->tableName();
			if ($t_rel_item->hasField('type_id')) { $va_selects[] = $vs_related_table.'.type_id item_type_id'; }
			
			$va_selects[] = $vs_related_table.'.'.$t_rel_item->primaryKey();
			
			// include dates in returned data
			if ($t_item_rel->hasField('effective_date')) {
				$va_selects[] = $vs_linking_table.'.sdatetime';
				$va_selects[] = $vs_linking_table.'.edatetime';
				
				$vb_uses_effective_dates = true;
			}
			
			if ($t_item_rel->hasField('type_id')) {
				$va_selects[] = $vs_linking_table.'.type_id relationship_type_id';
				
				require_once(__CA_MODELS_DIR__.'/ca_relationship_types.php');
				$t_rel = new ca_relationship_types();
				
				$vb_uses_relationship_types = true;
			}
			
			// limit related items to a specific type
			if ($vb_uses_relationship_types && isset($pa_options['restrict_to_relationship_types']) && $pa_options['restrict_to_relationship_types']) {
				if (!is_array($pa_options['restrict_to_relationship_types'])) {
					$pa_options['restrict_to_relationship_types'] = array($pa_options['restrict_to_relationship_types']);
				}
				
				if (sizeof($pa_options['restrict_to_relationship_types'])) {
					$va_rel_types = array();
					foreach($pa_options['restrict_to_relationship_types'] as $vm_type) {
						if (!$vm_type) { continue; }
						if (!($vn_type_id = $t_rel->getRelationshipTypeID($vs_linking_table, $vm_type))) {
							$vn_type_id = (int)$vm_type;
						}
						if ($vn_type_id > 0) {
							$va_rel_types[] = $vn_type_id;
							if (is_array($va_children = $t_rel->getHierarchyChildren($vn_type_id, array('idsOnly' => true)))) {
								$va_rel_types = array_merge($va_rel_types, $va_children);
							}
						}
					}
					
					if (sizeof($va_rel_types)) {
						$va_wheres[] = '('.$vs_linking_table.'.type_id IN ('.join(',', $va_rel_types).'))';
					}
				}
			}
			
			if ($vb_uses_relationship_types && isset($pa_options['exclude_relationship_types']) && $pa_options['exclude_relationship_types']) {
				if (!is_array($pa_options['exclude_relationship_types'])) {
					$pa_options['exclude_relationship_types'] = array($pa_options['exclude_relationship_types']);
				}
				
				if (sizeof($pa_options['exclude_relationship_types'])) {
					$va_rel_types = array();
					foreach($pa_options['exclude_relationship_types'] as $vm_type) {
						if ($vn_type_id = $t_rel->getRelationshipTypeID($vs_linking_table, $vm_type)) {
							$va_rel_types[] = $vn_type_id;
							if (is_array($va_children = $t_rel->getHierarchyChildren($vn_type_id, array('idsOnly' => true)))) {
								$va_rel_types = array_merge($va_rel_types, $va_children);
							}
						}
					}
					
					if (sizeof($va_rel_types)) {
						$va_wheres[] = '('.$vs_linking_table.'.type_id NOT IN ('.join(',', $va_rel_types).'))';
					}
				}
			}
		}
		
		// limit related items to a specific type
		if (isset($pa_options['restrict_to_type']) && $pa_options['restrict_to_type']) {
			if (!isset($pa_options['restrict_to_types']) || !is_array($pa_options['restrict_to_types'])) {
				$pa_options['restrict_to_types'] = array();
			}
			$pa_options['restrict_to_types'][] = $pa_options['restrict_to_type'];
		}
		
		$va_ids = caMergeTypeRestrictionLists($t_rel_item, $pa_options);
		
		if (is_array($va_ids) && (sizeof($va_ids) > 0)) {
			$va_wheres[] = '('.$vs_related_table.'.type_id IN ('.join(',', $va_ids).'))';
		}
		
		if (isset($pa_options['exclude_type']) && $pa_options['exclude_type']) {
			if (!isset($pa_options['exclude_types']) || !is_array($pa_options['exclude_types'])) {
				$pa_options['exclude_types'] = array();
			}
			$pa_options['exclude_types'][] = $pa_options['exclude_type'];
		}
		if (isset($pa_options['exclude_types']) && is_array($pa_options['exclude_types'])) {
			$va_ids = caMakeTypeIDList($t_rel_item->tableName(), $pa_options['exclude_types']);
			
			if (is_array($va_ids) && (sizeof($va_ids) > 0)) {
				$va_wheres[] = '('.$vs_related_table.'.type_id NOT IN ('.join(',', $va_ids).'))';
			}
		}
		
				
		if (is_array($va_get_where)) {
			foreach($va_get_where as $vs_fld => $vm_val) {
				if ($t_rel_item->hasField($vs_fld)) {
					$va_wheres[] = "({$vs_related_table_name}.{$vs_fld} = ".(!is_numeric($vm_val) ? "'".$this->getDb()->escape($vm_val)."'": $vm_val).")";
				}
			}
		}
		
		if ($vs_idno_fld = $t_rel_item->getProperty('ID_NUMBERING_ID_FIELD')) { $va_selects[] = $t_rel_item->tableName().'.'.$vs_idno_fld; }
		if ($vs_idno_sort_fld = $t_rel_item->getProperty('ID_NUMBERING_SORT_FIELD')) { $va_selects[] = $t_rel_item->tableName().'.'.$vs_idno_sort_fld; }
	
		$va_selects[] = $va_path[1].'.'.$vs_key;	
		
		if (isset($pa_options['fields']) && is_array($pa_options['fields'])) {
			$va_selects = array_merge($va_selects, $pa_options['fields']);
		}
		
		
		 // if related item is labelable then include the label table in the query as well
		$vs_label_display_field = null;
		if (method_exists($t_rel_item, "getLabelTableName")) {
			if($vs_label_table_name = $t_rel_item->getLabelTableName()) {           // make sure it actually has a label table...
				$va_path[] = $vs_label_table_name;
				$t_rel_item_label = $this->getAppDatamodel()->getTableInstance($vs_label_table_name);
				$vs_label_display_field = $t_rel_item_label->getDisplayField();

				if($vb_return_labels_as_array || (is_array($va_sort_fields) && sizeof($va_sort_fields))) {
					$va_selects[] = $vs_label_table_name.'.*';
				} else {
					$va_selects[] = $vs_label_table_name.'.'.$vs_label_display_field;
					$va_selects[] = $vs_label_table_name.'.locale_id';
					
					if ($t_rel_item_label->hasField('surname')) {	// hack to include fields we need to sort entity labels properly
						$va_selects[] = $vs_label_table_name.'.surname';
						$va_selects[] = $vs_label_table_name.'.forename';
					}
				}
				
				if ($t_rel_item_label->hasField('is_preferred') && (!isset($pa_options['return_non_preferred_labels']) || !$pa_options['return_non_preferred_labels'])) {
					$va_wheres[] = "(".$vs_label_table_name.'.is_preferred = 1)';
				}
			}
		}
				
		// return source info in returned data
		if ($t_item_rel && $t_item_rel->hasField('source_info')) {
			$va_selects[] = $vs_linking_table.'.source_info';
		}
		
		if (isset($pa_options['checkAccess']) && is_array($pa_options['checkAccess']) && sizeof($pa_options['checkAccess']) && $t_rel_item->hasField('access')) {
			$va_wheres[] = "(".$t_rel_item->tableName().'.access IN ('.join(',', $pa_options['checkAccess']).'))';
		}
		
		if ((!isset($pa_options['showDeleted']) || !$pa_options['showDeleted']) && $t_rel_item->hasField('deleted')) {
			$va_wheres[] = "(".$t_rel_item->tableName().'.deleted = 0)';
		}

		if($vb_self_relationship) {
			//
			// START - self relation
			//
			$va_rel_info = $this->getAppDatamodel()->getRelationships($va_path[0], $va_path[1]);
			if ($vs_label_table_name) {
				$va_label_rel_info = $this->getAppDatamodel()->getRelationships($va_path[0], $vs_label_table_name);
			}
			
			$va_rels = array();
			
			$vn_i = 0;
			foreach($va_rel_info[$va_path[0]][$va_path[1]] as $va_possible_keys) {
				$va_joins = array();
				$va_joins[] = "INNER JOIN ".$va_path[1]." ON ".$va_path[1].'.'.$va_possible_keys[1].' = '.$va_path[0].'.'.$va_possible_keys[0]."\n";
				
				if ($vs_label_table_name) {
					$va_joins[] = "INNER JOIN ".$vs_label_table_name." ON ".$vs_label_table_name.'.'.$va_label_rel_info[$va_path[0]][$vs_label_table_name][0][1].' = '.$va_path[0].'.'.$va_label_rel_info[$va_path[0]][$vs_label_table_name][0][0]."\n";
				}
				
				$vs_other_field = ($vn_i == 0) ? $va_rel_info[$va_path[0]][$va_path[1]][1][1] : $va_rel_info[$va_path[0]][$va_path[1]][0][1];
				$vs_direction =  (preg_match('!left!', $vs_other_field)) ? 'ltor' : 'rtol';
				
				$va_selects['row_id'] = $va_path[1].'.'.$vs_other_field.' AS row_id';
				
				$vs_order_by = '';
				$vs_sort_fld = '';
				if ($t_item_rel && $t_item_rel->hasField('rank')) {
					$vs_order_by = ' ORDER BY '.$t_item_rel->tableName().'.rank';
					$vs_sort_fld = 'rank';
					$va_selects[] = $t_item_rel->tableName().".rank";
				} else {
					if ($t_rel_item && ($vs_sort = $t_rel_item->getProperty('ID_NUMBERING_SORT_FIELD'))) {
						$vs_order_by = " ORDER BY ".$t_rel_item->tableName().".{$vs_sort}";
						$vs_sort_fld = $vs_sort;
						$va_selects[] = $t_rel_item->tableName().".{$vs_sort}";
					}
				}
				
				$vs_sql = "
					SELECT ".join(', ', $va_selects)."
					FROM ".$va_path[0]."
					".join("\n", $va_joins)."
					WHERE
						".join(' AND ', array_merge($va_wheres, array('('.$va_path[1].'.'.$vs_other_field .' IN ('.join(',', $va_row_ids).'))')))."
					{$vs_order_by}";
				//print "<pre>$vs_sql</pre>\n";
		
				$qr_res = $o_db->query($vs_sql);
				
				if ($vb_uses_relationship_types) { $va_rel_types = $t_rel->getRelationshipInfo($va_path[1]); }
				$vn_c = 0;
				if ($vn_start > 0) { $qr_res->seek($vn_start); }
				while($qr_res->nextRow()) {
					if ($vn_c >= $vn_limit) { break; }
					$va_row = $qr_res->getRow();
					$vn_id = $va_row[$vs_key].'/'.$va_row['row_id'];
					$vs_sort_key = $qr_res->get($vs_sort_fld);
					
					$vs_display_label = $va_row[$vs_label_display_field];
					//unset($va_row[$vs_label_display_field]);
					
					if (!$va_rels[$vs_sort_key][$vn_id]) {
						$va_rels[$vs_sort_key][$vn_id] = $qr_res->getRow();
					}
							
					if ($vb_uses_effective_dates) {	// return effective dates as display/parse-able text
						$va_rels[$vs_sort_key][$vn_id]['_key'] = $o_tep->setHistoricTimestamps($va_rels[$vs_sort_key][$vn_id]['sdatetime'], $va_rels[$vs_sort_key][$vn_id]['edatetime']);	
						$va_rels[$vs_sort_key][$vn_id]['effective_date'] = $o_tep->getText();
					}
					
					$va_rels[$vs_sort_key][$vn_id]['labels'][$qr_res->get('locale_id')] =  ($vb_return_labels_as_array) ? $va_row : $vs_display_label;
					$va_rels[$vs_sort_key][$vn_id]['_key'] = $vs_key;
					$va_rels[$vs_sort_key][$vn_id]['direction'] = $vs_direction;
					
					$vn_c++;
					if ($vb_uses_relationship_types) {
						$va_rels[$vs_sort_key][$vn_id]['relationship_typename'] = ($vs_direction == 'ltor') ? $va_rel_types[$va_row['relationship_type_id']]['typename'] : $va_rel_types[$va_row['relationship_type_id']]['typename_reverse'];
						$va_rels[$vs_sort_key][$vn_id]['relationship_type_code'] = $va_rel_types[$va_row['relationship_type_id']]['type_code'];
					}
				};
				$vn_i++;
			}
			
			ksort($va_rels);	// sort by sort key... we'll remove the sort key in the next loop while we add the labels
			
			// Set 'label' entry - display label in current user's locale
			$va_sorted_rels = array();
			foreach($va_rels as $vs_sort_key => $va_rels_by_sort_key) {
				foreach($va_rels_by_sort_key as $vn_id => $va_rel) {
					$va_tmp = array(0 => $va_rel['labels']);
					$va_sorted_rels[$vn_id] = $va_rel;
					$va_sorted_rels[$vn_id]['label'] = array_shift(caExtractValuesByUserLocale($va_tmp));
				}
			}
			$va_rels = $va_sorted_rels;
			
			//
			// END - self relation
			//
		} else {
			//
			// BEGIN - non-self relation
			//
			
			
			$va_wheres[] = "(".$this->tableName().'.'.$this->primaryKey()." IN (".join(",", $va_row_ids)."))";
			$vs_cur_table = array_shift($va_path);
			$va_joins = array();
			
			foreach($va_path as $vs_join_table) {
				$va_rel_info = $this->getAppDatamodel()->getRelationships($vs_cur_table, $vs_join_table);
				$va_joins[] = 'INNER JOIN '.$vs_join_table.' ON '.$vs_cur_table.'.'.$va_rel_info[$vs_cur_table][$vs_join_table][0][0].' = '.$vs_join_table.'.'.$va_rel_info[$vs_cur_table][$vs_join_table][0][1]."\n";
				$vs_cur_table = $vs_join_table;
			}
			
			$va_selects[] = $this->tableName().'.'.$this->primaryKey().' AS row_id';
			
			$vs_order_by = '';
			if ($t_item_rel && $t_item_rel->hasField('rank')) {
				$vs_order_by = ' ORDER BY '.$t_item_rel->tableName().'.rank';
			} else {
				if ($t_rel_item && ($vs_sort = $t_rel_item->getProperty('ID_NUMBERING_SORT_FIELD'))) {
					$vs_order_by = " ORDER BY ".$t_rel_item->tableName().".{$vs_sort}";
				}
			}
			
			$vs_sql = "
				SELECT ".join(', ', $va_selects)."
				FROM ".$this->tableName()."
				".join("\n", $va_joins)."
				WHERE
					".join(' AND ', $va_wheres)."
				{$vs_order_by}
			";
			
			//print "<pre>$vs_sql</pre>\n";
			$qr_res = $o_db->query($vs_sql);
			//print_r($o_db->getErrors());

			if ($vb_uses_relationship_types)  { 
				$va_rel_types = $t_rel->getRelationshipInfo($t_item_rel->tableName()); 
				$vs_left_table = $t_item_rel->getLeftTableName();
				$vs_direction = ($vs_left_table == $this->tableName()) ? 'ltor' : 'rtol';
			}
			$va_rels = array();
			$vn_c = 0;
			if ($vn_start > 0) { $qr_res->seek($vn_start); }
			while($qr_res->nextRow()) {
				if ($vn_c >= $vn_limit) { break; }
				if (isset($pa_options['returnAsSearchResult']) && $pa_options['returnAsSearchResult']) {
					$va_rels[] = $qr_res->get($t_rel_item->primaryKey());
					continue;
				}
				
				$va_row = $qr_res->getRow();
				$vs_v = $va_row[$vs_key];
				
				$vs_display_label = $va_row[$vs_label_display_field];
				//unset($va_row[$vs_label_display_field]);
				
				if (!isset($va_rels[$vs_v]) || !$va_rels[$vs_v]) {
					$va_rels[$vs_v] = $va_row;
				}
				
				if ($vb_uses_effective_dates) {	// return effective dates as display/parse-able text
					$va_rels[$vs_v]['_key'] = $o_tep->setHistoricTimestamps($va_rels[$vs_v]['sdatetime'], $va_rels[$vs_v]['edatetime']);	
					$va_rels[$vs_v]['effective_date'] = $o_tep->getText();
				}
				
				$va_rels[$vs_v]['labels'][$qr_res->get('locale_id')] =  ($vb_return_labels_as_array) ? $va_row : $vs_display_label;
				
				$va_rels[$vs_v]['_key'] = $vs_key;
				$va_rels[$vs_v]['direction'] = $vs_direction;
				
				$vn_c++;
				if ($vb_uses_relationship_types) {
					$va_rels[$vs_v]['relationship_typename'] = ($vs_direction == 'ltor') ? $va_rel_types[$va_row['relationship_type_id']]['typename'] : $va_rel_types[$va_row['relationship_type_id']]['typename_reverse'];
					$va_rels[$vs_v]['relationship_type_code'] = $va_rel_types[$va_row['relationship_type_id']]['type_code'];
				}
			}
			
			if (!isset($pa_options['returnAsSearchResult']) || !$pa_options['returnAsSearchResult']) {
				// Set 'label' entry - display label in current user's locale
				foreach($va_rels as $vs_v => $va_rel) {
					$va_tmp = array(0 => $va_rel['labels']);
					$va_rels[$vs_v]['label'] = array_shift(caExtractValuesByUserLocale($va_tmp));
				}
			}
			
			//
			// END - non-self relation
			//
		}
		
		//
		// Sort on fields if specified
		//
		if (is_array($va_sort_fields) && sizeof($va_rels)) {
			$va_ids = array();
			foreach($va_rels as $vn_i => $va_rel) {
				$va_ids[] = $va_rel[$t_rel_item->primaryKey()];
			}
			
			// Handle sorting on attribute values
			$vs_rel_pk = $t_rel_item->primaryKey();
			foreach($va_sort_fields as $vs_sort_field) {
				$va_tmp = explode('.', $vs_sort_field);
				if ($va_tmp[0] == $vs_related_table_name) {
					$qr_rel = $t_rel_item->makeSearchResult($va_tmp[0], $va_ids);
					
					$vs_table = array_shift($va_tmp);
					$vs_key = join(".", $va_tmp);
					while($qr_rel->nextHit()) {
						$vn_pk_val = $qr_rel->get($vs_table.".".$vs_rel_pk);
						foreach($va_rels as $vn_rel_id => $va_rel) {
							if ($va_rel[$vs_rel_pk] == $vn_pk_val) {
								$va_rels[$vn_rel_id][$vs_key] = $qr_rel->get($vs_sort_field, array("delimiter" => ";", 'sortable' => 1));
								break;
							}
						}
					}
					
				}
			}
			
			// Perform sort
			$va_rels = caSortArrayByKeyInValue($va_rels, $va_sort_fields);
		}
		
		return $va_rels;
	}
	# --------------------------------------------------------------------------------------------
	public function getTypeMenu() {
		$t_list = new ca_lists();
		$t_list->load(array('list_code' => $this->getTypeListCode()));
		
		$t_list_item = new ca_list_items();
		$t_list_item->load(array('list_id' => $t_list->getPrimaryKey(), 'parent_id' => null));
		$va_hierarchy = caExtractValuesByUserLocale($t_list_item->getHierarchyWithLabels());
		
		$va_types = array();
		if (is_array($va_hierarchy)) {
			
			$va_types_by_parent_id = array();
			$vn_root_id = null;
			foreach($va_hierarchy as $vn_item_id => $va_item) {
				if (!$vn_root_id) { $vn_root_id = $va_item['parent_id']; continue; }
				$va_types_by_parent_id[$va_item['parent_id']][] = $va_item;
			}
			foreach($va_hierarchy as $vn_item_id => $va_item) {
				if ($va_item['parent_id'] != $vn_root_id) { continue; }
				// does this item have sub-items?
				if (isset($va_types_by_parent_id[$va_item['item_id']]) && is_array($va_types_by_parent_id[$va_item['item_id']])) {
					$va_subtypes = $this->_getSubTypes($va_types_by_parent_id[$va_item['item_id']], $va_types_by_parent_id);
				} else {
					$va_subtypes = array();
				}
				$va_types[] = array(
					'displayName' =>$va_item['name_singular'],
					'parameters' => array(
						'type_id' => $va_item['item_id']
					),
					'navigation' => $va_subtypes
				);
			}
		}
		return $va_types;
	}
	# ------------------------------------------------------------------
	/**
	 * Override's BaseModel method to intercept calls for field 'idno'; uses the specified IDNumbering
	 * plugin to generate HTML for idno. If no plugin is specified then the call is passed on to BaseModel::htmlFormElement()
	 * Calls for fields other than idno are passed to BaseModel::htmlFormElement()
	 */
	public function htmlFormElement($ps_field, $ps_format=null, $pa_options=null) {
		if (!is_array($pa_options)) { $pa_options = array(); }
		foreach (array(
				'name', 'form_name', 'request', 'field_errors', 'display_form_field_tips', 'no_tooltips', 'label', 'readonly'
				) 
			as $vs_key) {
			if(!isset($pa_options[$vs_key])) { $pa_options[$vs_key] = null; }
		}
		
		if (
			($ps_field == $this->getProperty('ID_NUMBERING_ID_FIELD')) 
			&& 
			($this->opo_idno_plugin_instance)
			&&
			$pa_options['request']
		) {
			$this->opo_idno_plugin_instance->setValue($this->get($ps_field));
			$vs_element = $this->opo_idno_plugin_instance->htmlFormElement(
										$ps_field,  
										$va_errors, 
										array_merge(
											$pa_options,
											array(
												'error_icon' 				=> $pa_options['request']->getThemeUrlPath()."/graphics/icons/warning_small.gif",
												'progress_indicator'		=> $pa_options['request']->getThemeUrlPath()."/graphics/icons/indicator.gif",
												'show_errors'				=> ($this->getPrimaryKey()) ? true : false,
												'context_id'				=> isset($pa_options['context_id']) ? $pa_options['context_id'] : null,
												'table' 					=> $this->tableName(),
												'row_id' 					=> $this->getPrimaryKey(),
												'check_for_dupes'			=> true,
												'search_url'				=> caSearchUrl($pa_options['request'], $this->tableName(), '')
											)
										)
			);
			
			if (is_null($ps_format)) {
				if (isset($pa_options['field_errors']) && is_array($pa_options['field_errors']) && sizeof($pa_options['field_errors'])) {
					$ps_format = $this->_CONFIG->get('bundle_element_error_display_format');
					$va_field_errors = array();
					foreach($pa_options['field_errors'] as $o_e) {
						$va_field_errors[] = $o_e->getErrorDescription();
					}
					$vs_errors = join('; ', $va_field_errors);
				} else {
					$ps_format = $this->_CONFIG->get('bundle_element_display_format');
					$vs_errors = '';
				}
			}
			if ($ps_format != '') {
				$ps_formatted_element = $ps_format;
				$ps_formatted_element = str_replace("^ELEMENT", $vs_element, $ps_formatted_element);

				$va_attr = $this->getFieldInfo($ps_field);
				
				foreach (array(
						'DISPLAY_DESCRIPTION', 'DESCRIPTION', 'LABEL', 'DESCRIPTION', 
						) 
					as $vs_key) {
					if(!isset($va_attr[$vs_key])) { $va_attr[$vs_key] = null; }
				}
				
// TODO: should be in config file
$pa_options["display_form_field_tips"] = true;
				if (
					$pa_options["display_form_field_tips"] ||
					(!isset($pa_options["display_form_field_tips"]) && $va_attr["DISPLAY_DESCRIPTION"]) ||
					(!isset($pa_options["display_form_field_tips"]) && !isset($va_attr["DISPLAY_DESCRIPTION"]) && $vb_fl_display_form_field_tips)
				) {
					if (preg_match("/\^DESCRIPTION/", $ps_formatted_element)) {
						$ps_formatted_element = str_replace("^LABEL", isset($pa_options['label']) ? $pa_options['label'] : $va_attr["LABEL"], $ps_formatted_element);
						$ps_formatted_element = str_replace("^DESCRIPTION",$va_attr["DESCRIPTION"], $ps_formatted_element);
					} else {
						// no explicit placement of description text, so...
						$vs_field_id = '_'.$this->tableName().'_'.$this->getPrimaryKey().'_'.$pa_options["name"].'_'.$pa_options['form_name'];
						$ps_formatted_element = str_replace("^LABEL",'<span id="'.$vs_field_id.'">'.(isset($pa_options['label']) ? $pa_options['label'] : $va_attr["LABEL"]).'</span>', $ps_formatted_element);

						if (!$pa_options['no_tooltips']) {
							TooltipManager::add('#'.$vs_field_id, "<h3>".(isset($pa_options['label']) ? $pa_options['label'] : $va_attr["LABEL"])."</h3>".$va_attr["DESCRIPTION"]);
						}
					}
				} else {
					$ps_formatted_element = str_replace("^LABEL", (isset($pa_options['label']) ? $pa_options['label'] : $va_attr["LABEL"]), $ps_formatted_element);
					$ps_formatted_element = str_replace("^DESCRIPTION", "", $ps_formatted_element);
				}

				$ps_formatted_element = str_replace("^ERRORS", $vs_errors, $ps_formatted_element);
				$vs_element = $ps_formatted_element;
			}
			
			
			return $vs_element;
		} else {
			return parent::htmlFormElement($ps_field, $ps_format, $pa_options);
		}
	}
	# ----------------------------------------
	/**
	 * 
	 */
	public function getIDNoPlugInInstance() {
		return $this->opo_idno_plugin_instance;
	}
	# ----------------------------------------
	/**
	 *
	 */
	public function validateAdminIDNo($ps_admin_idno) {
		$va_errors = array();
		if ($this->_CONFIG->get('require_valid_id_number_for_'.$this->tableName()) && sizeof($va_admin_idno_errors = $this->opo_idno_plugin_instance->isValidValue($ps_admin_idno))) {
			$va_errors[] = join('; ', $va_admin_idno_errors);
		} else {
			if (!$this->_CONFIG->get('allow_duplicate_id_number_for_'.$this->tableName()) && sizeof($this->checkForDupeAdminIdnos($ps_admin_idno))) {
				$va_errors[] = _t("Identifier %1 already exists and duplicates are not permitted", $ps_admin_idno);
			}
		}
		
		return $va_errors;
	}
	# ------------------------------------------------------------------
	/**
	 *
	 */
	private function _getSubTypes($pa_subtypes, $pa_types_by_parent_id) {
		$va_subtypes = array();
		foreach($pa_subtypes as $vn_i => $va_type) {
			if (is_array($pa_types_by_parent_id[$va_type['item_id']])) {
				$va_subsubtypes = $this->_getSubTypes($pa_types_by_parent_id[$va_type['item_id']], $pa_types_by_parent_id);
			} else {
				$va_subsubtypes = array();
			}
			$va_subtypes[$va_type['item_id']] = array(
				'displayName' => $va_type['name_singular'],
				'parameters' => array(
					'type_id' => $va_type['item_id']
				),
				'navigation' => $va_subsubtypes
			);
		}
		
		return $va_subtypes;
	}
	# ------------------------------------------------------------------
	/**
	 *
	 */
	public function makeSearchResult($pm_rel_table_name_or_num, $pa_ids) {
		if (!is_array($pa_ids) || !sizeof($pa_ids)) { return null; }
		$pn_table_num = $this->getAppDataModel()->getTableNum($pm_rel_table_name_or_num);
		if (!($t_instance = $this->getAppDataModel()->getInstanceByTableNum($pn_table_num))) { return null; }
	
		if (!($vs_search_result_class = $t_instance->getProperty('SEARCH_RESULT_CLASSNAME'))) { return null; }
		require_once(__CA_LIB_DIR__.'/ca/Search/'.$vs_search_result_class.'.php');
		$o_data = new WLPlugSearchEngineCachedResult($pa_ids, array(), $t_instance->primaryKey());
		$o_res = new $vs_search_result_class();
		$o_res->init($t_instance->tableNum(), $o_data, array());
		
		return $o_res;
	}
	# --------------------------------------------------------------------------------------------
}
?>