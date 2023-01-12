<?php
/*
Plugin Name: Page Lock
Description: With this plugin you can limit changes in page options, deleting, creating selected pages.
Version: 1.21
Author: Michał Gańko
Author URI: http://flexphperia.net
*/

# get correct id for plugin
$thisfile=basename(__FILE__, ".php");

# register plugin
register_plugin(
	$thisfile, 
	'Page Lock', 	
	'1.21', 		
	'Michał Gańko',
	'http://flexphperia.net', 
	'With this plugin you can limit changes in page options, deleting, creating selected pages.',
	'plugins',
	'page_lock_admin_tab'  
);


if (!is_frontend()) { //only on backend
    add_action('edit-extras','page_lock_on_edit_extras'); 
    add_action('pages-main','page_lock_on_pages_main'); 
    add_action('header','page_lock_on_header'); 
    add_action('footer','page_lock_on_footer'); 

    add_action('plugins-sidebar', 'createSideMenu', [$thisfile, 'Configure Page Lock']);

	//include special pages class
	@include_once(GSPLUGINPATH.'i18n_specialpages/specialpages.class.php');

	$pl_i18nSecurityStore = ['normal' => [], 'special' => []]; //used to store some data
	$pl_spExists = class_exists('I18nSpecialPages'); //is special pages plugin exists or not (might be disabled but uploaded)
	
}

$pl_currFile = strtolower(basename($_SERVER['PHP_SELF'])); //currently loadded file

//delete lock security check
if ($pl_currFile =='deletefile.php') {
	if (isset($_GET['id'])) { 
			$id = $_GET['id'];
			$settings = page_lock_get_settings();
			if ($settings->delete_lock && in_array($id , $settings->delete_slugs)){
				die('Page Lock plugin: delete lock!');
		}
	}
}

//page options lock security check and creating page lock security check
if ($pl_currFile =='changedata.php' && isset($_POST['submitted'])) {
	$settings = page_lock_get_settings();
	
	$spExtraCheck = $pl_spExists && $settings->special_pages_security;
    
    //if we are here so we can create page it might be typical page or special one
    if ($spExtraCheck && isset($_POST['post-special']) && !empty($_POST['post-special'])){
        //we are sure that this is special one
        $spDef = I18nSpecialPages::getSettings($_POST['post-special']);

        if (empty($spDef)) //special type not found
            die('Page Lock plugin special pages security check: special type settings not found!');
    
        if ($spDef['template'] && $_POST['post-template'] != $spDef['template'])
            die('Page Lock plugin special pages security check: template change!');		

        if ($spDef['parent'] && $_POST['post-parent'] != $spDef['parent'])
            die('Page Lock plugin special pages security check: parent change!');
    }
		
	if ( isset($_POST['existing-url']) ){ //if editing currently existing page

		$oldSlug = $_POST['existing-url'];
		$newSlug = $_POST['post-id'];
		$checkParams = in_array($oldSlug , $settings->options_slugs); //we believe that some checkboxes are checked
		
		if ($checkParams || $spExtraCheck){
		
			$file = GSDATAPAGESPATH . $oldSlug . '.xml';
			$oldData = getXML($file);

			if ($checkParams){
				if ($settings->slug_lock && $oldSlug != $newSlug){
					die('Page Lock plugin: slug lock!');
				}
				if ($settings->template_lock && $_POST['post-template'] != (string)$oldData->template){
					die('Page Lock plugin: template lock!');
				}	

				//i18n navigation support, when saving translated page skip checking private and parent coz it's emptied by i18n nav plugin
				if (strpos($newSlug,'_') === false){
					if ($settings->visibility_lock && $_POST['post-private'] != (string)$oldData->private){
						die('Page Lock plugin: visibility lock!');
					}	
					if ($settings->parent_lock && $_POST['post-parent'] != (string)$oldData->parent){
						die('Page Lock plugin: parent lock!');
					}	
				}
			}
			
			//additional check for special pages, simply compare value from old data and new data
			if ($spExtraCheck){
				if (isset($_POST['post-special']) && !empty($_POST['post-special']) != (string)$oldData->special)
					die('Page Lock plugin special pages security check: special type change!');			
			}	
		}
	}
	else if (!isset($_POST['existing-url'])){ //if creating new page
		if (($settings->create_lock && !$settings->special_pages_enabled) || 
				($settings->create_lock && $settings->special_pages_enabled && !in_array($_POST['post-special'] , $settings->special_types))){
			die('Page Lock plugin: create page lock!');
		}
	}
}

//create lock, cloning page security check
if ($pl_currFile =='pages.php') {
	 if ( @$_GET['action'] == 'clone' ) { 
		 $id = $_GET['id'];
		 
		 $file = GSDATAPAGESPATH . $id .".xml";
		 $oldData = getXML($file);
		 
		 if ($oldData){
			 $settings = page_lock_get_settings();
			 if (($settings->create_lock && !$settings->special_pages_enabled) || (
					$settings->create_lock && $settings->special_pages_enabled && !in_array((string)$oldData->special , $settings->special_types))){
				die('Page Lock plugin: create pages lock!');
			 }
		 }
	 }
}



function page_lock_admin_tab() {
	if (isset($_POST['options_slugs'])){ //is post
	
		$visibility_lock = !empty($_POST['visibility_lock'])?1:0;
		$template_lock = !empty($_POST['template_lock'])?1:0;
		$parent_lock = !empty($_POST['parent_lock'])?1:0;
		$slug_lock = !empty($_POST['slug_lock'])?1:0;
		
		$preview_lock = !empty($_POST['preview_lock'])?1:0;
		$special_browse_remove = !empty($_POST['special_browse_remove'])?1:0;
		
		$delete_lock = !empty($_POST['delete_lock'])?1:0;
		$create_lock = !empty($_POST['create_lock'])?1:0;
		$special_pages_enabled = !empty($_POST['special_pages_enabled'])?1:0;
		
		$special_pages_security = !empty($_POST['special_pages_security'])?1:0;
        
		
		$success = page_lock_save_settings(	$visibility_lock, 
											$template_lock,
											$parent_lock,
											$slug_lock,
											$_POST['options_slugs'],
											$preview_lock,
											$special_browse_remove,
											$delete_lock,
											$_POST['delete_slugs'],
											$create_lock,
											$special_pages_enabled,
											$_POST['special_types'],
											$special_pages_security
										);
	}

	$settings = page_lock_get_settings();
	
	require_once('PageLock/views/configuration.html');
}

//removes private button from i18n_navigation Edit Navigation Structure option
function page_lock_on_header(){
    global $pl_currFile, $pagesArray, $pl_spExists, $pl_i18nSecurityStore;
	
	if ($pl_currFile == 'load.php' && @$_GET['id'] == 'PageLock'){
		?>
            <link rel="stylesheet" href="../plugins/PageLock/css/configuration.css" />	
		<?php
		return;
	}

    $settings = page_lock_get_settings();
    
    $spExtraCheck = $pl_spExists && $settings->special_pages_security;
    
    $script = '<script>$( document ).ready(function() {';
    
    //remove link for creating new page in support tab
    if ($pl_currFile == 'support.php') {
        echo '<style type="text/css">#maincontent{display:none}</style>';
        if ($settings->create_lock){
            $script .= '$(\'a[href="edit.php"]\').parent(\'li\').remove();';
        }
        $script .= '$(\'#maincontent\').show();';
    }  

    if ($pl_currFile =='pages.php' || $pl_currFile =='edit.php' || $pl_currFile =='load.php' )  {
        echo '<style type="text/css">#sidebar{display:none;}</style>'; //hide sidebar for changes

		if ($settings->special_browse_remove){ //remove browse button
			$script .= '$(\'#sidebar li a[href="load.php?id=i18n_specialpages&pages"]\').closest(\'li\').remove(); ';
		}

        if ($settings->create_lock){
            $script .= '$(\'#sb_newpage\').remove();';

            if (!$settings->special_pages_enabled){ //remove Create special page on sidebar
                $script .= '$(\'#sidebar li a[href="load.php?id=i18n_specialpages&create"]\').remove();';
            }
        }
        $script .= '$(\'#sidebar\').show();'; //show sidebar after changes
    }

    if ($pl_currFile =='load.php') {
        //i18n Multilevel navigation plugin
        if (@$_GET['id'] == 'i18n_navigation'){ 		
            
            $len = count($settings->options_slugs);
            
            echo '<style type="text/css">#maincontent{display:none;}</style>';
            
            if ($settings->visibility_lock && $len){
                foreach ($settings->options_slugs as $value)
                {
                    $script .='$(\'#editnav tr#tr-'.$value.' a.togglePrivate\').remove();';
                    
                    $script .='if ($(\'#editnav tr#tr-'.$value.'\').find(\'input[name$=private]\').val() == \'Y\'){
                                //if page is private and private locked then do not allow to change menu status from here
                                //coz clicking on Menu status button will dispatch handler that unprivates page
                                $(\'#editnav tr#tr-'.$value.' a.toggleMenu\').remove();
                            };';    
                }
            }	
        
            //removes all arrows for pages that has parent locked, special and normal ones
            $parentLockedSlugs = [];

            if ($settings->parent_lock && $len){ //normal pages
                $parentLockedSlugs = array_merge($parentLockedSlugs, $settings->options_slugs);
            }    
            if ($spExtraCheck){ //special pages	
				//create pagesArray its not ready , order off added hooks matters. this plugin added hook before "caching_function.php" did this
				getPagesXmlValues(true);

                //find what special slugs are locked
                $spDef = I18nSpecialPages::getSettings(null); //get all special pages definitions
                
                $specialSlugs = [];

                foreach ($pagesArray as $key => $value){
                    if ( isset($value['special']) && !empty($value['special']) ){
                    
                        if (!isset($spDef[$value['special']])) //special type not found
                            die('Page Lock plugin special pages security check: special type settings not found!');

                        if ( $spDef[$value['special']]['parent'] )
                            $specialSlugs[] = $value['url'];
                    }
                }

                $parentLockedSlugs = array_merge($parentLockedSlugs, $specialSlugs);
            }            
            if (count($parentLockedSlugs)){
                $script .= ' 
                    var parentLockedIds = ['.'"'.implode('","', $parentLockedSlugs).'"'.'];
                
                    $(\'#editnav tbody tr[id]\').each(function(){
                        var $tr = $(this),
                            slug = $tr.attr(\'id\').substr(3);

						if ($.inArray(slug, parentLockedIds) != -1){
							$tr.find(\'a.moveRight,a.moveLeft\').remove(); //removes arrows
						};
					});

					var $line = $(\'<div class="pl_line" style="position: absolute; height: 1px; width: 100%; border-top: 2px dotted #CF3805; z-index: 9999;"></div>\');
						
					//function used to show lines
					var showLines = function($row, $draggedRow, onBottom){ 
							var id = $draggedRow.attr(\'id\').substr(3),
								thisLevel = getLevel($draggedRow); //level of currently dragged item
							
							if ($.inArray(id, parentLockedIds) == -1)
								return; //continue loop
								
							if (getLevel($row) < thisLevel ){ //if row level is lower then dragged one = its parent
								var top = $row.find(\'td:first\').offset().top, //find top
									$cont = $(\'#maincontent .main\'),
									targetTop = onBottom ? top : top + ($row.find(\'td:first\').outerHeight(true)); //on bottom or top border
								
								$line.width($cont.width() + \'px\'); //set width as table width
								$line.css(\'top\', targetTop + \'px\').clone().appendTo($cont);
								return false; //will break loop
							}
						};

					//on sort start create some tips
					$( "#editnav tbody" ).on( "sortstart", function( event, ui ) {
						var	$prevs = ui.item.prevAll(\'tr\'), //all previous
							$nexts = ui.item.nextAll(\'tr\');

							
						//find parent and show line
						$prevs.each(function(){
							return showLines($(this), ui.item);
						});	

						//find next level and show line
						$nexts.each(function(){
							return showLines($(this), ui.item, true);
						});

					})
					.on( "sortstop", function( event, ui ) {
						$(\'.pl_line\').remove(); //remove lines
					
					});
                ';     
            }

            if ($settings->preview_lock){
                $script .='$(\'#editnav tr td.secondarylink:last-child\').remove();';
            }
            
            $script .= '$(\'#maincontent\').show();';
            
            
            //page options lock security check when saving data from Edit Navigation Structure
            if (!empty($_POST['save'])){ //saving
                if ($settings->visibility_lock){
                    for ($i=0; isset($_POST['page_'.$i.'_url']); $i++) {
                        $slug = $_POST['page_'.$i.'_url'];
                        if (in_array($slug , $settings->options_slugs)){

                            if ($_POST['page_'.$i.'_private'] != $pagesArray[$slug]['private'])
                                die('Page Lock plugin: private lock (i18n_navigation)!');	
                        }
                    }
                }
                
                
                //if parent lock is enabled and opstion slugs exists, sotre old page data before 18n navigation will save
                //used later in footer hook
                //now $pagesArray has old data
                if ( $spExtraCheck ){
                    foreach ($pagesArray as $key => $value){
                        //if its special, and this special has parent than store its original parent
                        if ( isset($value['special']) && !empty($value['special']) && in_array($value['url'], $specialSlugs) )
                            $pl_i18nSecurityStore['special'][$key] = $value['parent']; //stores old parent
                    }
                }
                
                if ( $settings->parent_lock && $len ){ //normal lock
                    foreach ($settings->options_slugs as $slug)
                    {
                        $pl_i18nSecurityStore['normal'][$slug] = $pagesArray[$slug]['parent']; //store parent if its slug is on locked pages
                    }
                }  
            }	
        }
    
        //i18n base plugin support, disables create page buttons
        if (@$_GET['id'] == 'i18n_base')  { 	
            echo '<style type="text/css">#maincontent{display:none;}</style>';
            if ($settings->create_lock){
                if ($settings->special_pages_enabled){ //some specials are enabled
                    
                    //find what is allowed for translating
                    $allowedSlugs = [];
                    foreach ($pagesArray as $key => $val){
                        if (strpos($key, '_') !== false) //find only default langague slugs
                            continue;
                            
                        //has special?
                        if (isset($val['special'])){
                            if (in_array($val['special'] , $settings->special_types)){ //on allowed list, collect id
                                $allowedSlugs[] = $key;
                            }
                        }
                     }
                 
                    //remove translation links only on not allowed special pages types
                    $script .= '
                    var pl_allowed_ids = ['.'"'.implode('","', $allowedSlugs).'"'.'],
                        pl_reg = new RegExp("edit\.php\\\?newid=([a-z0-9-]+)_.*&title", "i");
                       $(\'#editpages tbody tr\').each(function(){
                        var $tr = $(this),
                            $a = $tr.find(\'td a[href^="edit.php?newid="]\'), //not only in secondarylink td but also in delete class td
                            res = pl_reg.exec($a.attr(\'href\'));
 
                        if (!res || (res && $.inArray(res[1], pl_allowed_ids) == -1) ){
                            $a.remove();
                        }
                    });
                    ';
                }
                else{ //remove all create buttons, no specials allowed
                    $script .= '$(\'#editpages tbody tr td a[href^="edit.php?newid="]\').remove();';
                }
            }
            
            if ($settings->preview_lock){ //if preview lock
                //title=blank attribute giving us knowledge that this is for sure preview link
                $script .= '$(\'#editpages tr td.secondarylink a[target="_blank"]\').remove();';
            }      

			if ($settings->special_browse_remove){ //remove browse button
				$script .= '$(\'#sidebar li a[href="load.php?id=i18n_specialpages&pages"]\').closest(\'li\').remove(); ';
			}
            
            if ($settings->delete_lock){ //if delete lock activated, remove delete buttons
                $len = count($settings->delete_slugs);
                if ($len){
                    foreach ($settings->delete_slugs as $value)
                    {
                        $script .= '$(\'a.i18n-delconfirm[href^="deletefile.php?id='.$value.'&"]\').remove();';
                    }
                }
            }
            $script .= '$(\'#maincontent\').show();';
        }			
        
        //specialpages operations
        if (@$_GET['id'] == 'i18n_specialpages')  { 
            //hide content, wait untli js complete all deletions
            echo '<style type="text/css">#maincontent{display:none}</style>';
        
            if (isset($_GET['pages']) && isset($_GET['special'])){ //browsing pages by special type
                
                if ($settings->preview_lock){ //if preview lock
                    $script .= '$(\'#editpages tr td.secondarylink a[target="_blank"]\').remove();'; //remove only that needed to remove
                }
                
                if ($settings->delete_lock){ //if delete lock activated, remove delete buttons
                    $len = count($settings->delete_slugs);
                    if ($len){
                        foreach ($settings->delete_slugs as $value)
                        {
                            $script .= '$(\'a[href^="deletefile.php?id='.$value.'&"]\').remove();';
                        }
                    }
                }
                
                if ($settings->create_lock && !$settings->special_pages_enabled){
                    $script .= '$(\'a[href="edit.php?special='.$_GET['special'].'"]\').remove();'; //bottom link
                    //remove create buttons when i18n base is activated
                    $script .= '$(\'#editpages tbody tr td.secondarylink a[href^="edit.php?newid="]\').remove();'; 
                }
                else if ($settings->create_lock && $settings->special_pages_enabled){
                    if ( !in_array($_GET['special'] , $settings->special_types) ){ //remove create page link bottom of page
                        $script .= '$(\'a[href="edit.php?special='.$_GET['special'].'"]\').remove();';
                    }
                    
                    //remove create buttons when i18n base is activated
                    $script .= '
                    var pl_allowed = ['.'"'.implode('","', $settings->special_types).'"'.'],
                        pl_reg = /&metak=_special_(.+)%2C/;
                    $(\'#editpages tbody tr\').each(function(){
                        var $tr = $(this),
                            $a = $tr.find(\'td.secondarylink a[href^="edit.php?newid="]\'),
                            res =  pl_reg.exec($a.attr(\'href\'));
        
                        if (!res || (res && $.inArray(res[1], pl_allowed) == -1) ){
                            $a.remove();
                        }
                    });
                    ';
                }
    
                
            }
            else if ( isset($_GET['pages']) ){  //show all available special types
                if ($settings->create_lock){
                
                    if ($settings->special_pages_enabled){ //some specials are enabled
                        $script .= '
                        var pl_allowed = ['.'"'.implode('","', $settings->special_types).'"'.'];
                        $(\'#editspecial tbody tr\').each(function(){
                            var $tr = $(this),
                                $a = $tr.find(\'td a[href^="edit.php?special="]\');
            
                            if ($.inArray($a.attr(\'href\').replace("edit.php?special=", ""), pl_allowed) == -1 ){
                                $a.remove();
                            }
                        });
                        ';
                    }
                    else{ //remove all create buttons, no specials allowed
                        $script .= '$(\'#editspecial tbody tr td a[href^="edit.php?special="]\').remove();';
                    }
                }
                
            }
            else if ( isset($_GET['create']) ){ //Create New Special Page, list of special pages types
                if ($settings->create_lock && $settings->special_pages_enabled){ //remove all rows that are not allowed
                    $script .= '
                    var pl_allowed = ['.'"'.implode('","', $settings->special_types).'"'.'];
                    $(\'#editspecial tbody tr\').each(function(){
                        var $tr = $(this),
                            $a = $tr.find(\'td a\');
        
                        if ( $.inArray($a.attr(\'href\').replace("edit.php?special=", ""), pl_allowed) == -1 ){
                            $tr.remove();
                        }
                    });
                    ';
                }
            }
            
            $script .= '$(\'#maincontent\').show();';
            
        }	
         
    }

    $script .= '});</script>';
    
    echo $script;
}


function page_lock_on_footer(){
    
    //i18n Multilevel navigation plugin securoty check for changing parent
    //if pl_i18nSecurityStore not empty check should be applied
    //now $pagesarray has new values
    global $pl_i18nSecurityStore, $pagesArray;
    if (@$_GET['id'] == 'i18n_navigation' && !empty($_POST['save']) && $pl_i18nSecurityStore)  { 	   
        $normalWasChanged = false;
        $specialWasChanged = false;
        
        if (count($pl_i18nSecurityStore['normal'])){

            foreach ($pl_i18nSecurityStore['normal'] as $slug => $oldParent)//get slugs from storage temporary
            {            
                //compare old data and new data
                if ($pagesArray[$slug]['parent'] != $oldParent){
                    $normalWasChanged = true;
                }
            }
        }     

        if (count($pl_i18nSecurityStore['special'])){ //if there are any so this check is enabled in settings
            foreach ($pl_i18nSecurityStore['special'] as $slug => $oldParent)//get special slugs from storage temporary
            {            
                //compare old data and new data
                if ($pagesArray[$slug]['parent'] != $oldParent){
                    $specialWasChanged = true;
                }
            }
        }
        
        if ($normalWasChanged || $specialWasChanged){
            i18n_navigation_structure_undo();
            $s = ['Page Lock plugin:'];
           
            
            if ($normalWasChanged){
                $s[] = 'parent lock (i18n_navigation)!';
            }    
            if ($specialWasChanged){
                $s[] = 'special pages parent lock (i18n_navigation)!';
            }
			//show info and refresh
            die('<script>$( document ).ready(function() {alert(\''.implode('\n',$s).'\'); window.location = window.location.href;  } );</script>');	
        }   
    }

    
}

//removes delete buttons from standard pages list
function page_lock_on_pages_main() {
	$settings = page_lock_get_settings();

    //hide content, wait untli js complete 
    echo '<style type="text/css">#maincontent{display:none}</style>';
	
	$script = '<script>$( document ).ready(function() {';
	
	//if on restriction list, disable delet ebutton on pages list
	$len = count($settings->delete_slugs);
	if ($settings->delete_lock && $len){
		foreach ($settings->delete_slugs as $value)
		{
		  $script .= '$(\'#editpages a.delconfirm[href^="deletefile.php?id='.$value.'&"]\').remove();';
		}
	}
    
	if ($settings->preview_lock ){
		$script .= '$(\'#editpages td.secondarylink\').remove();';
	}
	
    
    $script .= '$(\'#maincontent\').show();';
	
	$script .= '});</script>';
	
	echo $script;
}

//disables page option fields
function page_lock_on_edit_extras() {
	global $url; //here is slug
	
	$settings = page_lock_get_settings();
    
    //hide content, wait untli js complete 
    echo '<style type="text/css">#maincontent{display:none}</style>';
	
	$script = '<script>$( document ).ready(function() {';
	
	//prepare page options
	if (in_array($url , $settings->options_slugs)){
		if ($settings->slug_lock){
			$script .= '$("#post-id").attr("readonly", "readonly");';
		}	
		if ($settings->visibility_lock){
			$script .= '$("#post-private").attr("disabled", "disabled");';
		}	
		if ($settings->parent_lock){
			$script .= '$("#post-parent").attr("disabled", "disabled");';
		}		
		if ($settings->template_lock){
			$script .= '$("#post-template").attr("disabled", "disabled");';
		}
		
		//remove disabled attribute before submitting, for sending POST data
		$script .= '
			$(\'#editform\').bind(\'submit\', function() {
				$(this).find(\'select\').removeAttr(\'disabled\');
			});
		';
	}
    
    //preview lock remov button
    if ($settings->preview_lock){
        $script .= '$("#metadata_toggle").prev("a").remove();';
    }	  
	
	//disable delete option from dropdown next to save button
	if ($settings->delete_lock && in_array($url , $settings->delete_slugs)){
		$script .= '$(\'li a[href^="deletefile.php?id='.$url.'&"]\').remove();';
	}	
	
	global $id;
	//disable clone option from dropdown next to save button
	if ($settings->create_lock && $id){ //if editing existing page, not new page
		global $data_edit; //SimpleXML to read from

		if ($settings->special_pages_enabled && in_array((string)$data_edit->special , $settings->special_types)){ 
		}
		else{
			$script .= '$(\'li a[href^="pages.php?id='.$url.'&action=clone"]\').remove();';
		}
	}
    
    $script .= '$(\'#maincontent\').show();';
	
	$script .= '});</script>'; //close script
	
	echo $script;
}

//retrieves settings from xml
function page_lock_get_settings() {
    $file = GSDATAOTHERPATH . 'pagelock.xml';
	
	if (!file_exists($file)) {
		page_lock_save_settings(); //create empty one
	}
	
	$data = getXML($file);
	
	$settings = new stdClass();

	$settings->visibility_lock = (bool) (string) $data->visibility_lock;
	$settings->template_lock = (bool) (string) $data->template_lock;
	$settings->parent_lock = (bool) (string) $data->parent_lock;
	$settings->slug_lock = (bool) (string) $data->slug_lock;
	
	$settings->options_slugs = (string)$data->options_slugs ? explode(',',(string)$data->options_slugs) : [];
    
	$settings->preview_lock = (bool) (string) $data->preview_lock;
	$settings->special_browse_remove = (bool) (string) $data->special_browse_remove;

	$settings->delete_lock = (bool) (string) $data->delete_lock;
	$settings->delete_slugs =  (string)$data->delete_slugs ? explode(',',(string)$data->delete_slugs) : [];	
	
	$settings->create_lock = (bool) (string) $data->create_lock;
	$settings->special_pages_enabled = (bool) (string) $data->special_pages_enabled;
	$settings->special_types =  explode(',',(string)$data->special_types);
	
	$settings->special_pages_security = (bool) (string) $data->special_pages_security;
	
	return $settings;
}

//saves settings to xml
function page_lock_save_settings(	$visibility_lock = 0,
									$template_lock = 0, 
									$parent_lock = 0,
									$slug_lock = 0,
									$options_slugs = '',
                                    $preview_lock = 0,
									$special_browse_remove = 0,
									$delete_lock = 0, 
									$delete_slugs = '',
									$create_lock = 0, 
									$special_pages_enabled = 0, 
									$special_types = '' ,
									$special_pages_security = 0
								) {
    $file = GSDATAOTHERPATH . 'pagelock.xml';
	
	$xml = new SimpleXMLExtended('<?xml version="1.0" encoding="UTF-8"?><settings></settings>');
	$xml->addChild('visibility_lock', $visibility_lock);
	$xml->addChild('template_lock', $template_lock);
	$xml->addChild('parent_lock', $parent_lock);
	$xml->addChild('slug_lock', $slug_lock);
	
	$xml->addChild('preview_lock', $preview_lock);
	$xml->addChild('special_browse_remove', $special_browse_remove);
    
	$xml->addChild('delete_lock', $delete_lock);
	
	$obj = $xml->addChild('options_slugs');
	$obj->addCData($options_slugs);	
	
	$obj = $xml->addChild('delete_slugs');
	$obj->addCData($delete_slugs);
	
	$xml->addChild('create_lock', $create_lock);
	$xml->addChild('special_pages_enabled', $special_pages_enabled);
	
	$obj = $xml->addChild('special_types');
	$obj->addCData($special_types);

	$xml->addChild('special_pages_security', $special_pages_security);
	
	  # write data to file
	return XMLsave($xml, $file) === true ? true : false;
}
