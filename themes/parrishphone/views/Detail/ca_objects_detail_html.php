<?php
/* ----------------------------------------------------------------------
 * pawtucket2/themes/default/views/Detail/ca_objects_detail_html.php : 
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
	$t_object = 					$this->getVar('t_item');
	$vn_object_id = 				$t_object->get('object_id');
	$vs_title = 					$this->getVar('label');
	
	$t_rep = 						$this->getVar('t_primary_rep');
	
	$va_access_values = 		$this->getVar('access_values');

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
		
		<div id='detailTitle'><h1><?php print $vs_title; ?></h1></div>
<?php
			if ($t_rep && $t_rep->getPrimaryKey()) {
			
			$tag =	$t_rep->getMediaInfo('media', 'small');
			$tag_width = $tag['WIDTH'];	
			
			$zoom_tag =	$t_rep->getMediaInfo('media', 'large');
			$zoom_tag_width = $tag['WIDTH'];
			$zoom_tag_height = $tag['HEIGHT'];			

			print "<div id='objDetailImageContainer' >";
		

?>
			<a href='#' class='zoomLink' onclick="$('#zoomImage').show();$('#objDetailImageContainer').show();"><?php print $t_rep->getMediaTag('media', 'small');?></a>
<?php			
			# --- identifier
			if($va_caption = $t_object->get('ca_objects.caption')){
				print "<div class='photoCaption unit'><b>"._t("Caption").": </b>".$va_caption."</div><!-- end unit -->";
			}
?>				
			</div><!-- end objDetailImageContainer -->
<?php		
		if ($zoom_tag_width >= $zoom_tag_height) {		
			$imgStyle = "height:100%;";
		} else {
			
			$imgStyle = "width:100%;";
		}		
			print "<div id='zoomImage' style='display:none; height:100%; width:100%; position:absolute; top:0; left:0; z-index:10000; overflow:auto;'>";
?>
				<a href='#' class='zoomLink' onclick="$('#zoomImage').hide();">
<?php				
					print "<img src='".$t_rep->getMediaUrl('media', 'large')."' style={$imgStyle}>";
?>	
				</a>		
			</div>
				
<!--<script type="text/javascript">
	jQuery(document).ready(function() { 
		jQuery("#objDetailImageContainer").load("<?php print caNavUrl($this->request, 'Detail', 'Object', 'GetObjectDetailMedia', array('object_id' => $t_object->get("object_id"), 'representation_id' => $t_rep->getPrimaryKey())); ?>");
	});
</script>-->
			
<?php
	

			# --- identifier
			if($t_object->get('idno')){
				print "<div class='unit'><b>"._t("Identifier").":</b> ".$t_object->get('idno')."</div><!-- end unit -->";
			}
			
			if($this->getVar('typename')){
				print "<div class='unit'><b>"._t("Object type").":</b> ".unicode_ucfirst($this->getVar('typename'))."</div><!-- end unit -->";
			}
			# --- parent hierarchy info
			if($t_object->get('parent_id')){
				print "<div class='unit'><b>"._t("Part Of")."</b>: ".caNavLink($this->request, $t_object->get("ca_objects.parent.preferred_labels.name"), '', 'Detail', 'Object', 'Show', array('object_id' => $t_object->get('parent_id')))."</div>";
			}
			# --- attributes
			$va_attributes = $this->request->config->get('ca_objects_detail_display_attributes');
			if(is_array($va_attributes) && (sizeof($va_attributes) > 0)){
				foreach($va_attributes as $vs_attribute_code){
					if($t_object->get("ca_objects.{$vs_attribute_code}")){
						print "<div class='unit'><b>".$t_object->getAttributeLabel($vs_attribute_code).":</b> ".$t_object->get("ca_objects.{$vs_attribute_code}")."</div><!-- end unit -->";
					}
				}
			}
			print "<div style='width:100%; height:1px; border-bottom: 1px solid #eee;'></div>";
			# --- description
			if($this->request->config->get('ca_objects_description_attribute')){
				if($vs_description_text = $t_object->get("ca_objects.".$this->request->config->get('ca_objects_description_attribute'))){
					print "<div class='unit'><div id='description'>".$vs_description_text."</div></div><!-- end unit -->";				

				}
			}
						}
			if($this->request->config->get('show_add_this')){
?>
				<!-- AddThis Button BEGIN 
				<div id="addThis"><a class="addthis_button" href="http://www.addthis.com/bookmark.php?v=250&amp;username=xa-4baa59d57fc36521"><img src="http://s7.addthis.com/static/btn/v2/lg-share-en.gif" width="125" height="16" alt="Bookmark and Share" style="border:0;"/></a><script type="text/javascript" src="http://s7.addthis.com/js/250/addthis_widget.js#username=xa-4baa59d57fc36521"></script></div>
				AddThis Button END -->
<?php
			}						
			# --- child hierarchy info
			$va_children = $t_object->get("ca_objects.children.preferred_labels", array('returnAsArray' => 1, 'checkAccess' => $va_access_values));
			if(sizeof($va_children) > 0){
				print "<div class='unit'><h2>"._t("Part%1", ((sizeof($va_children) > 1) ? "s" : ""))."</h2> ";
				$i = 0;
				foreach($va_children as $va_child){
					# only show the first 5 and have a more link
					if($i == 5){
						print "<div id='moreChildrenLink'><a href='#' onclick='$(\"#moreChildren\").slideDown(250); $(\"#moreChildrenLink\").hide(1); return false;'>["._t("More")."]</a></div><!-- end moreChildrenLink -->";
						print "<div id='moreChildren' style='display:none;'>";
					}
					print "<div>".caNavLink($this->request, $va_child['name'], '', 'Detail', 'Object', 'Show', array('object_id' => $va_child['object_id']))."</div>";
					$i++;
					if($i == sizeof($va_children)){
						print "</div><!-- end moreChildren -->";
					}
				}
				print "</div><!-- end unit -->";
			}
			# --- map
			if($this->request->config->get('ca_objects_map_attribute') && $t_object->get($this->request->config->get('ca_objects_map_attribute'))){
				$o_map = new GeographicMap(306, 200, 'map');
				$o_map->mapFrom($t_object, $this->request->config->get('ca_objects_map_attribute'));
				print "<div class='listItems' data-role='collapsible' data-mini='true' data-inset='false'>";
				print "<h2>"._t("Map")."</h2><!-- end collapseListHeading -->";
				
				print "<div id='detailMap'>".$o_map->render('HTML')."</div>";
				print "</div><!-- end map -->";
			}
			$va_entities = $t_object->get("ca_entities", array("returnAsArray" => 1, 'checkAccess' => $va_access_values));
			$va_occurrences = $t_object->get("ca_occurrences", array("returnAsArray" => 1, 'checkAccess' => $va_access_values));
			$va_places = $t_object->get("ca_places", array("returnAsArray" => 1, 'checkAccess' => $va_access_values));
			$va_collections = $t_object->get("ca_collections", array("returnAsArray" => 1, 'checkAccess' => $va_access_values));
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
			$va_terms = $t_object->get("ca_list_items", array("returnAsArray" => 1, 'checkAccess' => $va_access_values));
			if (sizeof($va_terms)) {
				print "<div class='collapseListHeading'><a href='#' class='scrollButton' onclick='$(\"#relatedSubjects\").slideToggle(250); return false;'>"._t("Keywords")."</a></div><!-- end collapseListHeading -->";
				print "<div id='relatedSubjects' class='listItems' style='display:none;'>";
			
				foreach($va_terms as $va_term_info){
					print "<div class='item'>".caNavLink($this->request, $va_term_info['label'], '', '', 'Search', 'Index', array('search' => $va_term_info['label']))."</div>";
				}
?>
				</div><!-- end relatedSubjects -->
<?php
			}
			# --- output related object images as links
			$va_related_objects = $t_object->get("ca_objects", array("returnAsArray" => 1, 'checkAccess' => $va_access_values));
			if (sizeof($va_related_objects)) {
				print "<div class='collapseListHeading'><a href='#' class='scrollButton' onclick='$(\"#relatedObjects\").slideToggle(250); return false;'>"._t("Related Objects")."</a></div><!-- end collapseListHeading -->";
				print "<div id='relatedObjects' class='listItems' style='display:none;'>";

				foreach($va_related_objects as $vn_rel_id => $va_info){
					$t_rel_object = new ca_objects($va_info["object_id"]);
					$va_reps = $t_rel_object->getPrimaryRepresentation(array('icon', 'small'), null, array('return_with_access' => $va_access_values));
					print "<div class='item'>";
					print "<div class='thumb'>".caNavLink($this->request, $va_reps['tags']['icon'], '', 'Detail', 'Object', 'Show', array('object_id' => $va_info["object_id"]))."</div>";
					print caNavLink($this->request, $va_info['label']."<br/>".$va_info['idno'], '', 'Detail', 'Object', 'Show', array('object_id' => $va_info["object_id"]));
					print "<div style='clear:left;'><!-- empty --></div></div><!-- end item -->";
				}
				print "</div><!-- end relatedObjects -->";
			}
if (!$this->request->config->get('dont_allow_registration_and_login')) {
		# --- user data --- comments - ranking - tagging
?>			
		<div class='collapseListHeading'><a href='#' onclick='$("#objUserData").slideToggle(250); return false;'><?php print _t("Comments, tags and rank"); ?></a></div><!-- end collapseListHeading -->
		<div id="objUserData">
<?php
			if($this->getVar("ranking")){
?>
				<h2 id="ranking"><?php print _t("Average User Ranking"); ?> <img src="<?php print $this->request->getThemeUrlPath(); ?>/graphics/user_ranking_<?php print $this->getVar("ranking"); ?>.gif" width="104" height="15" border="0" style="margin-left: 20px;"></h2>
<?php
			}
			$va_tags = $this->getVar("tags_array");
			if(is_array($va_tags) && sizeof($va_tags) > 0){
				$va_tag_links = array();
				foreach($va_tags as $vs_tag){
					$va_tag_links[] = caNavLink($this->request, $vs_tag, '', '', 'Search', 'Index', array('search' => $vs_tag));
				}
?>
				<h2><?php print _t("Tags"); ?></h2>
				<div id="tags">
					<?php print implode($va_tag_links, ", "); ?>
				</div>
<?php
			}
			$va_comments = $this->getVar("comments");
			if(is_array($va_comments) && (sizeof($va_comments) > 0)){
?>
				<h2><div id="numComments">(<?php print sizeof($va_comments)." ".((sizeof($va_comments) > 1) ? _t("comments") : _t("comment")); ?>)</div><?php print _t("User Comments"); ?></h2>
<?php
				foreach($va_comments as $va_comment){
?>
					<div class="comment">
						<?php print $va_comment["comment"]; ?>
					</div>
					<div class="byLine">
						<?php print $va_comment["author"].", ".$va_comment["date"]; ?>
					</div>
<?php
				}
			}else{
				if(!$vs_tags && !$this->getVar("ranking")){
					$vs_login_message = _t("Login/register to be the first to rank, tag and comment on this object!");
				}
			}
			if($this->getVar("ranking") || (is_array($va_tags) && (sizeof($va_tags) > 0)) || (is_array($va_comments) && (sizeof($va_comments) > 0))){
?>
				<div class="divide" style="margin:12px 0px 10px 0px;"><!-- empty --></div>
<?php			
			}
		if($this->request->isLoggedIn()){
?>
			<h2><?php print _t("Add your rank, tags and comment"); ?></h2>
			<form method="post" action="<?php print caNavUrl($this->request, 'Detail', 'Object', 'saveCommentRanking', array('object_id' => $vn_object_id)); ?>" name="comment">
				<div class="formLabel">Rank
					<select name="rank">
						<option value="">-</option>
						<option value="1">1</option>
						<option value="2">2</option>
						<option value="3">3</option>
						<option value="4">4</option>
						<option value="5">5</option>
					</select>
				</div>
				<div class="formLabel"><?php print _t("Tags (separated by commas)"); ?></div>
				<input type="text" name="tags">
				<div class="formLabel"><?php print _t("Comment"); ?></div>
				<textarea name="comment" rows="5"></textarea>
				<br><a href="#" name="commentSubmit" onclick="document.forms.comment.submit(); return false;"><?php print _t("Save"); ?></a>
			</form>
<?php
		}else{
			if (!$this->request->config->get('dont_allow_registration_and_login')) {
				print "<p>".caNavLink($this->request, (($vs_login_message) ? $vs_login_message : _t("Please login/register to rank, tag and comment on this item.")), "", "", "LoginReg", "form", array('site_last_page' => 'ObjectDetail'))."</p>";
			}
		}
?>		
		</div><!-- end objUserData-->
<?php
	}
?>
		<div id='bottom'></div>
	</div><!-- end detailBody -->

	

	
