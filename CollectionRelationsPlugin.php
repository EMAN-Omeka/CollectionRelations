<?php
  
  class CollectionRelationsPlugin extends Omeka_Plugin_AbstractPlugin
{
    protected $_filters = array(
        'admin_collections_form_tabs',
      );
    protected $_hooks = array(
        'define_routes',     
        'after_save_collection',
        'install',
        'uninstall',   
    );    
    
    public function filterAdminCollectionsFormTabs($tabs, $args) {
        $collection = $args['collection'];
        $formSelectProperties = get_table_options('ItemRelationsProperty');
        $subjectRelations = self::prepareSubjectRelations($collection);
        $objectRelations = self::prepareObjectRelations($collection);        
        ob_start();
        include 'links_form.php';
        $content = ob_get_contents();
        ob_end_clean();
        $tabs['Collection Relations'] = $content;
        return $tabs;      
    }
 
     /**
     * Install the plugin.
     */
    public function hookInstall()
    {
      $db = get_db();
        $sql = "
        CREATE TABLE IF NOT EXISTS `$db->CollectionRelationsRelation` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `subject_collection_id` int(10) unsigned NOT NULL,
            `property_id` int(10) unsigned NOT NULL,
            `object_collection_id` int(10) unsigned NOT NULL,
        		`relation_comment` varchar(200) NOT NULL DEFAULT '',             
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
        $db->query($sql);
    }


    /**
     * Uninstall the plugin.
     */
    public function hookUninstall()
    {
        $db = $this->_db;

        // Drop the relations table.
        $sql = "DROP TABLE IF EXISTS `$db->CollectionRelationsRelation`";
        $db->query($sql);

    }

    /**
     * Save the collection relations after saving an collection add/edit form.
     *
     * @param array $args
     */
    public function hookAfterSaveCollection($args)
    {    	    	    	    	
        if (!$args['post']) {
            return;
        }

        $record = $args['record'];
        $post = $args['post'];

        $db = $this->_db;
        					
        // Save item relations.
        foreach ($post['collection_relations_property_id'] as $key => $propertyId) {
            self::insertCollectionRelation(
                $record,
                $propertyId,
                $post['collection_relations_collection_relation_object_collection_id'][$key]
            );
        }
       
        // Delete item relations.
        if (isset($post['collection_relations_collection_relation_delete'])) {
            foreach ($post['collection_relations_collection_relation_delete'] as $itemRelationId) {
                $collectionRelation = $db->getTable('CollectionRelationsRelation')->find($itemRelationId);
                // When an item is related to itself, deleting both relations
                // simultaneously will result in an error. Prevent this by
                // checking if the item relation exists prior to deletion.
                if ($collectionRelation) {
                    $collectionRelation->delete();
                }
            }
        }
            // update the comment when the comment is edited in subject
        if (isset($post['collection_relations_subject_comment'])) {
        	if (isset($post['collection_relations_subject_comment'])) {
        		$comments = array();
        		foreach($post['collection_relations_subject_comment'] as $key => $value) {
//         			if ($value) {
        				$comments[$key] = $value;
//         			}
        		}
        		if ($comments) {
	        		$commentIds = implode(',', array_keys($comments));
	        		// Optimized the update query to avoid multiple execution.
	        		$sql = "UPDATE `$db->CollectionRelationsRelation` SET relation_comment = CASE id ";
	        		foreach ($comments as $commentId => $comment) {
	        			$sql .= sprintf(' WHEN %d THEN %s', $commentId, $db->quote($comment));
	        		}
	        		$sql .= " END WHERE id IN ($commentIds)";
	        		$db->query($sql);
        		}
        	}
        	else {
        		$this->_helper->flashMessenger(__('There was an error in the collection relation comments.'), 'error');
        	}
        }
    }    
    
    public function hookDefineRoutes($args) {
  		$router = $args['router'];
   		$router->addRoute(
  				'collection_relations_ajax_collections_autocomplete',
  				new Zend_Controller_Router_Route(
  						'collectionrelationsajax/:title', 
  						array(
  								'module' => 'collection-relations',
  								'controller'   => 'ajax',
  								'action'       => 'collectionajax',
  								'title'					=> ''
  						)
  				)
  		);
    }
    
    /**
     * Prepare subject item relations for display.
     *
     * @param Item $item
     * @return array
     */
    public static function prepareSubjectRelations(Collection $collection)
    {
        $subjects = get_db()->getTable('CollectionRelationsRelation')->findBySubjectCollectionId($collection->id);
        $subjectRelations = array();

        foreach ($subjects as $subject) {
            if (!($collection = get_record_by_id('collection', $subject->object_collection_id))) {
                continue;
            }           
            $subjectRelations[] = array(
                'collection_relation_id' => $subject->id,
                'object_collection_id' => $subject->object_collection_id,
                'object_collection_title' => self::getCollectionTitle($collection),
            		'relation_comment' => $subject->relation_comment,            		
                'relation_text' => $subject->getPropertyText(),
                'relation_description' => $subject->property_description,
/*
            		'item_thumbnail' => self::getItemThumbnail($subject->object_item_id),            		
            		'item_collection' => $collectionTitle
*/
            );
        }
        if ($subjectRelations) {
					$subjectRelations = self::sortByRelationTitle($subjectRelations, 'relation_text', 'object_collection_title', 'relation_description');
        }       
        return $subjectRelations;
    }

    /**
     * Prepare object item relations for display.
     *
     * @param Item $item
     * @return array
     */
    public static function prepareObjectRelations(Collection $collection)
    {
        $objects = get_db()->getTable('CollectionRelationsRelation')->findByObjectCollectionId($collection->id);
        $objectRelations = array();
        foreach ($objects as $object) {
            if (!($collection = get_record_by_id('collection', $object->subject_collection_id))) {
                continue;
            }
            $objectRelations[] = array(
                'collection_relation_id' => $object->id,
                'subject_collection_id' => $object->subject_collection_id,
                'subject_collection_title' => self::getCollectionTitle($collection),
            		'relation_comment' => $object->relation_comment,            		
                'relation_text' => $object->getPropertyText(),
                'relation_description' => $object->property_description,
/*
            		'item_thumbnail' => self::getItemThumbnail($object->subject_item_id),
            		'subject_collection' => $collectionTitle
*/
            );
        }       
        if ($objectRelations) {
        	$objectRelations = self::sortByRelationTitle($objectRelations, 'relation_text', 'subject_collection_title', 'relation_description');
        }
        return $objectRelations;
    }  
    
    /**
     * Return a item's title.
     *
     * @param Item $item The item.
     * @return string
     */
    public static function getCollectionTitle($collection)
    {
        $title = metadata($collection, array('Dublin Core', 'Title'), array('no_filter' => true));
        if (!trim($title)) {
            $title = '#' . $collection->id;
        }
        return $title;
    }  
    /**
     * Return an associative array sorted by 1 column then another. 
     *
     * @param associative array to sort  
     * @param first column's name 
     * @param second column's name
     * @return array
     */
    public static function sortByRelationTitle($array, $firstSort, $secondSort, $thirdSort) {
    	foreach ($array as $key => $row) {
    		$sort1[$key]  = $row[$firstSort];
    		$sort2[$key] = $row[$secondSort];
    		$sort3[$key] = $row[$thirdSort];
    	}
    	array_multisort($sort1, SORT_STRING, $sort2, SORT_STRING, $sort3, SORT_STRING, $array);
    	return $array;
    }
    /**
     * Insert an item relation.
     *
     * @param Item|int $subjectItem
     * @param int $propertyId
     * @param Item|int $objectItem
     * @return bool True: success; false: unsuccessful
     */
    public static function insertCollectionRelation($subjectCollection, $propertyId, $objectCollection)
    {
        // Only numeric property IDs are valid.
        if (!is_numeric($propertyId)) {
            return false;
        }

        // Set the subject item.
        if (!($subjectCollection instanceOf Collection)) {
            $subjectCollection = get_db()->getTable('Collection')->find($subjectCollection);
        }

        // Set the object item.
        if (!($objectCollection instanceOf Collection)) {
            $objectCollection = get_db()->getTable('Collection')->find($objectCollection);
        }

        // Don't save the relation if the subject or object items don't exist.
        if (!$subjectCollection || !$objectCollection) {
            return false;
        }

        $collection = new CollectionRelationsRelation;
        $collection->subject_collection_id = $subjectCollection->id;
        $collection->property_id = $propertyId;
        $collection->object_collection_id = $objectCollection->id;
        $collection->relation_comment = strlen($relationComment) ? $relationComment : '';        
        $collection->save();

        return true;
    }            
}