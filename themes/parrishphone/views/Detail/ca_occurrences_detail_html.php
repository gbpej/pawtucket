<?php
/* ----------------------------------------------------------------------
 * pawtucket2/themes/default/views/ca_occurrences_detail_html.php : 
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2009-2010 Whirl-i-Gig
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
 * ----------------------------------------------------------------------
 */
	$t_occurrence 		= $this->getVar('t_item');
	$vn_occurrence_id 	= $t_occurrence->getPrimaryKey();
	
	$vs_title 			= $this->getVar('label');
	
	$va_access_values	= $this->getVar('access_values');

if (!$this->request->isAjax()) {
?>
	<div id="detailBody">
<?php
		if (($this->getVar('is_in_result_list')) && ($vs_back_link = ResultContext::getResultsLinkForLastFind($this->request, 'ca_objects', _t("Back"), ''))) {
?>
			<div id="pageNav">
<?php
			if ($this->getVar('previous_id')) {
				print caNavLink($this->request, "&lsaquo; "._t("Previous"), 'value', 'Detail', 'Object', 'Show', array('object_id' => $this->getVar('previous_id')), array('id' => 'previous'));
			}else{
				print "&lsaquo; "._t("Previous");
			}
			print "&nbsp;&nbsp;&nbsp;";
			print ResultContext::getResultsLinkForLastFind($this->request, 'ca_objects', _t("Back"), 'value');
			print "&nbsp;&nbsp;&nbsp;";
			if ($this->getVar('next_id') > 0) {
				print caNavLink($this->request, _t("Next")." &rsaquo;", 'value', 'Detail', 'Object', 'Show', array('object_id' => $this->getVar('next_id')), array('id' => 'next'));
			}else{
				print _t("Next")." &rsaquo;";
			}
?>
			</div><!-- end pagenav -->
<?php			
		}
?>
		<h1><?php print $vs_title; ?></h1>
<?php
			# --- identifier
			if($t_occurrence->get('idno')){
				print "<div class='unit'><b>"._t("Identifier")."</b>: ".$t_occurrence->get('idno')."</div><!-- end unit -->";
			}
			if($this->getVar('typename')){
				print "<div class='unit'><b>"._t("Type").":</b> ".unicode_ucfirst($this->getVar('typename'))."</div><!-- end unit -->";
			}
			# --- attributes
			$va_attributes = $this->request->config->get('ca_occurrences_detail_display_attributes');
			if(is_array($va_attributes) && (sizeof($va_attributes) > 0)){
				foreach($va_attributes as $vs_attribute_code){
					if($t_occurrence->get("ca_occurrences.".$vs_attribute_code)){
						print "<div class='unit'><b>".$t_occurrence->getAttributeLabel($vs_attribute_code).":</b> ".$t_occurrence->get("ca_occurrences.".$vs_attribute_code)."</div><!-- end unit -->";
					}
				}
			}
			# --- description
			if($this->request->config->get('ca_occurrences_description_attribute')){
				if($vs_description_text = $t_occurrence->get("ca_occurrences.".$this->request->config->get('ca_occurrences_description_attribute'))){
					print "<div class='unit'><div id='description'>".$vs_description_text."</div></div><!-- end unit -->";				

				}
			}
			$va_entities = $t_occurrence->get("ca_entities", array("returnAsArray" => 1, 'checkAccess' => $va_access_values));
			$va_occurrences = $t_occurrence->get("ca_occurrences", array("returnAsArray" => 1, 'checkAccess' => $va_access_values));
			$va_places = $t_occurrence->get("ca_places", array("returnAsArray" => 1, 'checkAccess' => $va_access_values));
			$va_collections = $t_occurrence->get("ca_collections", array("returnAsArray" => 1, 'checkAccess' => $va_access_values));
			if(sizeof($va_entities) || sizeof($va_occurrences) || sizeof($va_places) || sizeof($va_collections)){
				print "<div class='listItems' data-role='collapsible' data-mini='true' data-inset='false'>";
				print "<h2>"._t("Related Information")."</h2><!-- end collapseListHeading -->";
				# --- entities
				if(sizeof($va_entities) > 0){	
					foreach($va_entities as $va_entity) {
						print "<div class='item'>".(($this->request->config->get('allow_detail_for_ca_entities')) ? caNavLink($this->request, $va_entity["label"], '', 'Detail', 'Entity', 'Show', array('entity_id' => $va_entity["entity_id"])) : $va_entity["label"])." (".$va_entity['relationship_typename'].")</div>";
					}
				}
				
				# --- occurrences
				$va_sorted_occurrences = array();
				if(sizeof($va_occurrences) > 0){
					$t_occ = new ca_occurrences();
					$va_item_types = $t_occ->getTypeList();
					foreach($va_occurrences as $va_occurrence) {
						$t_occ->load($va_occurrence['occurrence_id']);
						$va_sorted_occurrences[$va_occurrence['item_type_id']][$va_occurrence['occurrence_id']] = $va_occurrence;
					}
					
					foreach($va_sorted_occurrences as $vn_occurrence_type_id => $va_occurrence_list) {
						foreach($va_occurrence_list as $vn_rel_occurrence_id => $va_info) {
							print "<div class='item'>".(($this->request->config->get('allow_detail_for_ca_occurrences')) ? caNavLink($this->request, $va_info["label"], '', 'Detail', 'Occurrence', 'Show', array('occurrence_id' => $vn_rel_occurrence_id)) : $va_info["label"])." (".$va_info['relationship_typename'].")</div>";
						}
					}
				}
				# --- places
				if(sizeof($va_places) > 0){
					foreach($va_places as $va_place_info){
						print "<div class='item'>".(($this->request->config->get('allow_detail_for_ca_places')) ? caNavLink($this->request, $va_place_info['label'], '', 'Detail', 'Place', 'Show', array('place_id' => $va_place_info['place_id'])) : $va_place_info['label'])." (".$va_place_info['relationship_typename'].")</div>";
					}
				}
				# --- collections
				if(sizeof($va_collections) > 0){
					foreach($va_collections as $va_collection_info){
						print "<div class='item'>".(($this->request->config->get('allow_detail_for_ca_collections')) ? caNavLink($this->request, $va_collection_info['label'], '', 'Detail', 'Collection', 'Show', array('collection_id' => $va_collection_info['collection_id'])) : $va_collection_info['label'])." (".$va_collection_info['relationship_typename'].")</div>";
					}
				}
				print "</div><!-- end relatedAuthorities -->";
			}
			# --- vocabulary terms
			$va_terms = $t_occurrence->get("ca_list_items", array("returnAsArray" => 1, 'checkAccess' => $va_access_values));
			if (sizeof($va_terms)) {
				print "<div class='collapseListHeading'><a href='#' onclick='$(\"#relatedSubjects\").slideToggle(250); return false;'>"._t("Subjects")."</a></div><!-- end collapseListHeading -->";
				print "<div id='relatedSubjects' class='listItems' style='display:none;'>";
			
				foreach($va_terms as $va_term_info){
					print "<div class='item'>".caNavLink($this->request, $va_term_info['label'], '', '', 'Search', 'Index', array('search' => $va_term_info['label']))."</div>";
				}
?>
				</div><!-- end relatedSubjects -->
<?php
			}

}
		// set parameters for paging controls view
		$this->setVar('other_paging_parameters', array(
			'occurrence_id' => $vn_occurrence_id
		));
		print $this->render('related_objects.php');

if (!$this->request->isAjax()) {
?>
</div><!-- end detailBody -->
<?php
}
?>