<?php defined('SYSPATH') OR die('No direct access allowed.');

/**
 * Modified Preorder Tree Traversal Class. The API is a bit different then other, not much. 
 * But I think this makes more sence.
 * 
 * For documentation on how this even works, see: http://dev.mysql.com/tech-resources/articles/hierarchical-data.html
 *
 * @package ORM_MPTT
 * @author Mathew Davies
 * @author Kiall Mac Innes
 * @author Paul Banks
 * @author Brotkin Ivan
 * @author Brandon Summers
 * @author Michiel Eghuizen
 **/
abstract class ORM_MPTT_Core extends ORM
{
	/**
	 * Left Column
	 *
	 * @var string
	 **/
	protected $left_column = 'lft';
	
	/**
	 * Right Column
	 *
	 * @var string
	 **/
	protected $right_column = 'rgt';
	
	/**
	 * Level Column
	 *
	 * @var string
	 **/
	protected $level_column = 'lvl';
	
	/**
	 * Parent Column
	 *
	 * @var string
	 **/
	protected $parent_column = 'parent_id';
	
	/**
	 * Scope Column, which gives your tree a seperate ID, so you can make multiple trees in your table.
	 * could be handy for example, for threaded forum posts, then the first forum post is a new root node with a new scope.
	 * 
	 * So making a new root node will also increment the scope number of that item, 
	 * because this class thinks that each scope can only have 1 root node.
	 *
	 * @link http://forum.kohanaframework.org/discussion/6730/what-is-scope-in-mptt-for-example-orm_mptt
	 * @var string
	 **/
	protected $scope_column = 'scope';
	
	
	/**
	 * If the table is locked or not. This is because you can use functions inside other functions which both use lock and unlock
	 *
	 * @var boolean
	 **/
	protected $locked = false;
	
	/**
	 * Stores the old parent_id on load, so it can be used on save function to trigger the move to another parent.
	 * 
	 * @var int
	 */
	protected $_old_parentid = NULL;

	/**
	 * Constructor. Sets the left_column as default orderby, if not already set.
	 *
	 * @access public
	 * @return void
	 **/
	public function __construct($id = NULL)
	{
		if (empty($this->sorting))
			$this->sorting = array($this->scope_column => 'ASC', $this->left_column => 'ASC', $this->primary_key => 'ASC');
		
		parent::__construct($id);
	}

	/**
	 * Locks the MPTT table.
	 *
	 * @access protected
	 * @return void
	 **/
	protected function lock()
	{
		return true;
		if (!$this->locked) 
		{
			$this->db->query('LOCK TABLE '.$this->table_name.' WRITE');
			$this->locked = TRUE;
		}
	}
	
	/**
	 * Unlocks the MPTT table.
	 *
	 * @access protected
	 * @return void
	 **/
	protected function unlock()
	{
		return true;
		if ($this->locked)
		{
			$this->db->query('UNLOCK TABLES');
			$this->locked = FALSE;
		}
	}

	/**
	 * Does the node have a child?
	 *
	 * $node = ORM::factory('table', 12)->has_children();
	 *
	 * if ($node)
	 * {
	 *	 print 'This node has a child.';
	 * }
	 *
	 * @access public
	 * @return boolean
	 **/
	public function has_children()
	{
		return ($this->size() > 2);
	}
	
	/**
	 * Is the current node a leaf node
	 * (Has no children)
	 *
	 * $node = ORM::factory('table', 12)->is_leaf();
	 *
	 * if ($node)
	 * {
	 *	 print 'This node is a leaf node.';
	 * }
	 *
	 * @access public
	 * @return boolean
	 **/
	public function is_leaf()
	{
		return (!$this->has_children());
	}
	
	/**
	 * Is the current node a descendant of the supplied node.
	 *
	 * @access public
	 * @param ORM_MPTT|int $target ORM_MPTT object or primary key value of target node
	 * @return boolean
	 * @author Gallery3
	 **/
	public function is_descendant($target)
	{
		if (!($target instanceof $this))
			$target = self::factory($this->object_name, $target);
		
		return (
					$this->left() > $target->left()
					AND $this->right() < $target->right()
				);
	}
	
	/**
	 * Is the current node a direct child of the supplied node.
	 *
	 * @access public
	 * @param ORM_MPTT|int $target ORM_MPTT object or primary key value of target node
	 * @return boolean
	 **/
	public function is_child($target)
	{
		if (!($target instanceof $this))
			$target = self::factory($this->object_name, $target);
		
		//you can use parent_id column in here, so you dont have to call the database for the parent
		return ($this->parent_id() === $target->primary_key());
	}
	
	/**
	 * Is the current node the direct parent of the supplied node.
	 *
	 * @access public
	 * @param ORM_MPTT|int $target ORM_MPTT object or primary key value of target node
	 * @return boolean
	 **/
	public function is_parent($target)
	{
		if (!($target instanceof $this))
			$target = self::factory($this->object_name, $target);
		
		//you can use parent_id column in here, so you dont have to call the database for the parent
		return ($this->primary_key() === $target->parent_id());
	}
	
	/**
	 * Is the current node a sibling of the supplied node
	 *
	 * @access public
	 * @param ORM_MPTT|int $target ORM_MPTT object or primary key value of target node
	 * @return boolean
	 **/
	public function is_sibling($target)
	{
		if (!($target instanceof $this))
			$target = self::factory($this->object_name, $target);
		
		//the same node as itself is never a sibling but does have the same primary key as that one of the parent.
		if ($this->primary_key() === $target->primary_key())
			return FALSE;
		
		//you can use parent_id column in here, so you dont have to call the database for the parent
		return ($this->parent_id() === $target->parent_id());
	}
	
	/**
	 * Is the current node a root node?
	 *
	 * $is_root = ORM::factory('table', 12)->is_root();
	 *
	 * if ($node)
	 * {
	 *	 print 'This node is a root node.';
	 * }
	 *
	 * @access public
	 * @return boolean
	 **/
	public function is_root()
	{
		return ($this->left() === 1 || $this->parent_id() === NULL);
	}
	
	/**
	 * Checks if the current node is one of the parents of a specific node.
	 * 
	 * @param ORM_MPTT|int $target ORM_MPTT object or primary key value of target node
	 * @return boolean
	 */
	public function is_in_parents($target) {
		if (!($target instanceof $this))
			$target = self::factory($this->object_name, $target);
		
		return $target->is_descendant($this);
	}
	
	
	/**
	 * Returns the root node off the tree of the current item
	 *
	 * $root = $this->root();
	 *
	 * @access public
	 * @param int $scope the scope of the item if it's not the current scope
	 * @return ORM_MPTT|FALSE
	 **/
	public function root($scope = NULL)
	{
		if (empty($scope))
		{
			if ($this->loaded === TRUE)
				$scope = $this->scope();
			else
				return FALSE;
		}
		// SELECT * FROM table WHERE parent_id IS NULL AND scope = 1 AND lft <= 5 AND rgt >= 6 LIMIT 1
		return self::factory($this->object_name)
						->where(array(
							$this->parent_column => NULL,
							$this->scope_column => $scope,
							$this->left_column . ' <=', intval($this->left()),
							$this->right_column . ' >=', intval($this->right())
						))
						->find();
	}
	
	/**
	 * Returns all root nodes of all scopes. Because there can only be one root node per scope
	 *
	 * $roots = $this->roots();
	 *
	 * @access public
	 * @param boolean $get_all_scopes
	 * @return array<object>
	 **/
	public function roots()
	{
		// SELECT * FROM table WHERE parent_id IS NULL AND scope = 1 ORDER BY lft ASC
		return self::factory($this->object_name)
						->where(array($this->parent_column => NULL))
						->orderby($this->scope_column, 'ASC')
						->orderby($this->left_column, 'ASC')
						->orderby($this->primary_key, 'ASC')->find_all();
	}
	
	/**
	 * Returns the parent node
	 *
	 * $node = $this->parent();
	 *
	 * @access public
	 * @return ORM_MPTT
	 **/
	public function parent()
	{	
		if ($this->is_root())
			return NULL;
		
		// SELECT * FROM `table` WHERE `left` < 9 AND `right` > 10 ORDER BY `right` ASC LIMIT 1
		// OR better: SELECT * FROM table WHERE parent_id = 4
		return self::factory($this->object_name, $this->parent_id());
	}
	
	/**
	 * Returns all of the parents of this node. If you want the direct parent only use: $this->parent().
	 *
	 * $parents = $this->parents();
	 * 
	 * @access public
	 * @param bool include root node
	 * @param bool include current node
	 * @param $direction Direction to order the left column by
	 * @return ORM_MPTT
	 **/
	public function parents($with_root_nodes = TRUE, $with_self = FALSE, $direction = 'ASC')
	{
		$suffix = ($with_self) ? '=' : '';
		
		$result = self::factory($this->object_name)
						->where($this->left_column. ' <' . $suffix, $this->left())
						->where($this->right_column. ' >' . $suffix, $this->right())
						->where($this->scope_column, $this->scope())
						->orderby($this->left_column, $direction);
		
		if (!$with_root_nodes)
			$result->where($this->left_column. ' !=',  1);
			
		return $result->find_all();
	}
	
	/**
	 * Returns the children of this node
	 *
	 * $children = $this->children();
	 *
	 * @access public
	 * @param $with_self boolean Should it include this node?
	 * @param $direction string ASC or DESC
	 * @param $limit int if you want to have a limit on the results
	 * @return ORM_MPTT
	 **/
	public function children($with_self = FALSE, $direction = 'ASC', $limit = NULL)
	{	
		$child_level = $this->level() + 1;
		
		$result = self::factory($this->object_name)
						->where($this->left_column. ' >=', $this->left())
						->where($this->right_column. ' <=', $this->right())
						->where($this->scope_column, $this->scope())
						->orderby($this->left_column, $direction);
		
		//orwhere is tricky here so I use a creative way of 'AND WHERE'
		if ($with_self) {
			$result->where($this->level_column. ' >=', intval($this->level()));
			$result->where($this->level_column. ' <=', intval($this->level()) + 1);
		} else {
			$result->where($this->level_column. ' =', intval($this->level()) + 1);
		}
		
		if (!empty($limit))
			$result->limit($limit);
		
		return $result->find_all();
	}
	
	/**
	 * Returns the subtree of the currently loaded
	 * node
	 *
	 * $root = ORM::factory('table')->root()->find();
	 *
	 * $descendants = ORM::factory('table', $root->id)->descendants;
	 *
	 * @access public
	 * @param $with_self boolean Should it include this node?
	 * @param $direction string ASC or DESC
	 * @param $limit int if you want to have a limit on the results
	 * @return ORM_MPTT
	 **/
	public function descendants($with_self = FALSE, $direction = 'ASC', $limit = NULL)
	{
		$suffix = ($with_self) ? '=' : '';
		
		$result = self::factory($this->object_name)
						->where($this->left_column. ' >' . $suffix, $this->left())
						->where($this->right_column. ' <' . $suffix, $this->right())
						->where($this->scope_column, $this->scope())
						->orderby($this->left_column, $direction);
		
		if (!empty($limit))
			$result->limit($limit);
			
		return $result->find_all();
	}
	
	/**
	 * Returns the siblings of this node
	 *
	 * $siblings = $this->siblings();
	 *
	 * @access public
	 * @param $with_self boolean Should it include this node?
	 * @param $direction string ASC or DESC
	 * @param $limit int if you want to have a limit on the results
	 * @return ORM_MPTT
	 **/
	public function siblings($with_self = FALSE, $direction = 'ASC', $limit = NULL)
	{
		$suffix = ($with_self) ? '=' : '';
		
		$result = self::factory($this->object_name)
						->where($this->parent_column, $this->parent_id())
						->where($this->scope_column, $this->scope())
						->orderby($this->left_column, $direction);
		
		if (!empty($limit))
			$result->limit($limit);
		if (!$with_self)
			$result->where($this->primary_key . ' !=', $this->primary_key());
			
		return $result->find_all();
	}
	
	/**
	 * Returns a list of leaf nodes.
	 *
	 * $leaf_nodes = ORM::factory('table', 1)->leaves;
	 *
	 * @access public
	 * @param $direct_children_only boolean include direct children only
	 * @param $direction string ASC or DESC
	 * @param $limit int if you want to have a limit on the results
	 * @return ORM_MPTT
	 **/
	public function leaves($direct_children_only = TRUE, $direction = 'ASC', $limit = NULL)
	{
		$result = self::factory($this->object_name)
						->where($this->left_column, $this->right_column . ' - 1', FALSE)
						->where($this->scope_column, $this->scope())
						->where($this->left_column. ' >', $this->left())
						->where($this->right_column. ' <', $this->right())
						->orderby($this->left_column, $direction);
		
		if ($direct_children_only)
			$result->where($this->parent_column, $this->primary_key());
		if (!empty($limit))
			$result->limit($limit);
			
		return $result->find_all();
	}
	
	/**
	 * Returns a full hierarchical tree, with or without scope checking.
	 * 
	 * @param object $use_scope [optional] all trees or only the tree with scope
	 * @param object $scope [optional] the scope of the tree, if NULL it uses the current scope of the current node
	 * @param int $max_depth [optional] the maximum depth of the tree
	 * @return ORM_MPTT
	 */
	public function fulltree($use_scope = FALSE, $scope = NULL, $max_depth = NULL) {
		$result = self::factory($this->object_name)
						->orderby($this->scope_column, 'ASC')
						->orderby($this->left_column, 'ASC')
						->orderby($this->primary_key, 'ASC');
		
		if ($use_scope)
		{
			$scope = (!empty($scope)) ? $scope : $this->scope();
			
			$result->where($this->scope_column, $scope);
		}
		
		if (!empty($max_depth))
		{
			$result->where($this->level_column . ' <=', $max_depth);
		}
		
		return $result->find_all();
	}
	
	/**
	 *  Returns the size of the current node.
	 *    For example:
	 *    	left = 5, right = 6
	 *    	(right - left) + 1
	 *    	result: 2
	 *    	why: because it takes 5 as well as 6, so 2 numbers in this case
	 *
	 * @access public
	 * @return int
	 **/
	public function size()
	{
		return ($this->right() - $this->left() + 1);
	}
	
	/**
	 * Returns the number of descendants the current node has.
	 * 
	 * @access public
	 * @return int
	 */
	public function count()
	{
		$size = $this->size() - 2;
		
		if ($size <= 0)
			return 0;
		
		return round($size / 2);
	}
	
	public function left()
	{
		return intval($this->{$this->left_column});
	}
	
	public function right()
	{
		return intval($this->{$this->right_column});
	}
	
	public function scope()
	{
		return intval($this->{$this->scope_column});
	}
	
	public function level()
	{
		return intval($this->{$this->level_column});
	}
	
	public function parent_id()
	{
		return intval($this->{$this->parent_column});
	}
	
	public function primary_key()
	{
		return intval($this->{$this->primary_key});
	}

	/**
	 * Create a gap in the tree to make room for a new node.
	 *
	 * @access protected
	 * @param $start integer Start position.
	 * @param $size integer The size of the gap (default is 2)
	 * @param $scope integer [optional] The scope of the tree on which to create the space
	 * @return void
	 **/
	protected function create_space($start, $size = 2, $scope = NULL)
	{
		if (empty($scope))
			$scope = intval($this->scope());
		
		// Update the left values.
		/*$this->db->update(
				$this->table_name,
				array(
					$this->left_column .  ' = ' . $this->left_column . ' + ' . intval($size)
				),
				array(
					$this->left_column . ' >= ' => intval($start),
					$this->scope_column => $scope
				)
		);*/
		$this->db->query(
				'UPDATE '
					.$this->table_name.
				' SET '
					.$this->left_column.' = '.$this->left_column.' + '.intval($size)
				.' WHERE '
					.$this->left_column.' >= '.intval($start)
					.' AND ' . $this->scope_column . ' = ' . $scope
				);

		// Now the right.
		/*$this->db->update(
				$this->table_name,
				array(
					$this->right_column .  ' = ' . $this->right_column . ' + ' . intval($size)
				),
				array(
					$this->right_column . ' >= ' => intval($start),
					$this->scope_column => $scope
				)
		);*/
		$this->db->query(
				'UPDATE '
					.$this->table_name
				.' SET '
					.$this->right_column.' = '.$this->right_column.' + '.intval($size)
				.' WHERE '
					.$this->right_column.' >= '.intval($start)
					.' AND ' . $this->scope_column . ' = ' . $scope
				);
	}
	
	/**
	 * Closes a gap in a tree. Mainly used after a node has
	 * been removed.
	 *
	 * @access protected
	 * @param $start integer Start position.
	 * @param $size integer The size of the gap (default is 2)
	 * @param $scope integer [optional] The scope of the tree on which to remove the space
	 * @return void
	 **/
	protected function delete_space($start, $size = 2, $scope = NULL)
	{
		if (empty($scope))
			$scope = intval($this->scope());
		
		// Update the left values.
		/*$this->db->update(
				$this->table_name,
				array(
					$this->left_column .  ' = ' . $this->left_column . ' - ' . intval($size)
				),
				array(
					$this->left_column . ' >= ' => intval($start),
					$this->scope_column => $scope
				)
		);*/
		$this->db->query(
				'UPDATE '
					.$this->table_name.
				' SET '
					.$this->left_column.' = '.$this->left_column.' - '.intval($size)
				.' WHERE '
					.$this->left_column.' >= '.intval($start)
					.' AND ' . $this->scope_column . ' = ' . $scope
				);

		// Now the right.
		/*$this->db->update(
				$this->table_name,
				array(
					$this->right_column .  ' = ' . $this->right_column . ' - ' . intval($size)
				),
				array(
					$this->right_column . ' >= ' => intval($start),
					$this->scope_column => $scope
				)
		);*/
		$this->db->query(
				'UPDATE '
					.$this->table_name
				.' SET '
					.$this->right_column.' = '.$this->right_column.' - '.intval($size)
				.' WHERE '
					.$this->right_column.' >= '.intval($start)
					.' AND ' . $this->scope_column . ' = ' . $scope
				);
	}
	
	/**
	 * Inserts this node as new root node, or moves a node to root. All in a new scope, or the scope given.
	 * 
	 * @param $scope [optional] int|null the scope on which to insert this node
	 * @access protected
	 * @return mixed
	 */
	protected function save_as_root($scope = NULL)
	{
		//if the node already exists and is already root, return false
		if ($this->loaded === TRUE && $this->is_root())
			return FALSE;
		
		// Increment next scope
		if (empty($scope))
			$scope = self::get_next_scope();
		elseif (! self::scope_available($scope))
			return FALSE;
		
		$locked = false;
		
		//delete node space first
		if ($this->loaded === TRUE) {
			// Lock the table
			$this->lock();
			$locked = true;
			
			$this->delete_space($this->left(), $this->size(), $this->scope());
			
			if ($this->has_children()) {
				$level_offset = 1 - $this->level();
				$offset = 1 - $this->left();
				
				// Update the childeren for the new place in the tree
				//the SET's in this query cannot be right by the syntax of it
				/*$this->db->update(
						$this->table_name,
						array(
							$this->left_column .  ' = ' . $this->left_column . ' + ' . intval($offset),
							$this->right_column .  ' = ' . $this->right_column . ' + ' . intval($offset),
							$this->level_column . ' = ' . $this->level_column . ' + ' . intval($level_offset),
							$this->scope_column . ' = ' . intval($scope)
						),
						array(
							$this->left_column . ' >' => $this->left(),
							$this->right_column . ' <' => $this->right(),
							$this->scope_column => $this->scope()
						)
				);*/
				$this->db->query(
						'UPDATE '.$this->table_name.
						' SET '
							.$this->left_column.' = '.$this->left_column.' + '.intval($offset) . ', '
							.$this->right_column.' = '.$this->right_column.' + '.intval($offset) . ', '
							.$this->level_column.' = '.$this->level_column.' + '.intval($level_offset) . ', '
							.$this->scope_column.' = '.intval($scope)
						.' WHERE '
							.$this->left_column.' > '.$this->left()
							.' AND ' . $this->right_column.' < '.$this->right()
							.' AND ' . $this->scope_column . ' = ' . intval($this->scope())
				);
			}
		}
		
		$this->{$this->scope_column} = $scope;
		$this->{$this->level_column} = 1;
		$this->{$this->left_column} = 1;
		$this->{$this->right_column} = 2;
		$this->{$this->parent_column} = NULL;
		
		$returnval = $this;
		
		try
		{
			$returnval = parent::save();
			
			if ($locked)
				$this->unlock();
		}
		catch (Exception $e)
		{
			if ($locked)
				$this->unlock();
			
			// Some fields didn't validate, throw an exception
			throw $e;
		}
		
		return $returnval;
	}
	
	/**
	 * Inserts the node. This cannot be called insert() because that override the $this->db->insert.
	 * 
	 * @param object $target
	 * @param object $copy_left_from
	 * @param object $left_offset
	 * @param object $level_offset
	 * @return 
	 */
	protected function insert_node($target, $copy_left_from, $left_offset, $level_offset)
	{
		// Insert should only work on new nodes.. if its already it the tree it needs to be moved!
		if ($this->loaded === TRUE)
			return FALSE;
		
		if (!($target instanceof $this))
			$target = self::factory($this->object_name, $target);
		
		// Only existing targets can have children
		if (!$target->loaded)
			return FALSE;
		
		// Lock the table.
		$this->lock();
		
		// It's best that the target isn't changed so the update will work ok (works best after the lock)
		$target->reload();
		
		//set the MPTT columns
		$this->{$this->left_column} = $target->{$copy_left_from} + $left_offset;
		$this->{$this->right_column} = $this->left() + 1;
		$this->{$this->level_column} = $target->level() + $level_offset;
		$this->{$this->scope_column} = $target->scope();
		$this->{$this->parent_column} = $target->primary_key();
		
		//create a space the get this node as child node of the target
		$this->create_space($this->left(), 2, $target->scope());
		
		$returnval = $this;
		
		try
		{
			$returnval = parent::save();
		}
		catch (Exception $e)
		{
			// We had a problem saving, make sure we clean up the tree
			$this->delete_space($this->left(), 2, $target->scope());
			
			$this->unlock();
			
			// Some fields didn't validate, throw an exception
			throw $e;
		}
			
		$this->unlock();
		
		return $returnval;
	}
	
	/**
	 * Moves the node to another place in the tree. Able to move between scopes.
	 *
	 * @param $new_left integer The value for the new left.
	 * @return boolean
	 **/
	protected function move($target, $use_left_column = TRUE, $left_offset, $level_offset, $allow_root_target = TRUE)
	{
		// Move should only work on existing nodes..
		if (!$this->loaded)
			return FALSE;
		
		if (!($target instanceof $this))
			$target = self::factory($this->object_name, $target);
		
		// Only existing targets can have children
		if (!$target->loaded)
			return FALSE;
		
		 // store the changed parent id before reload
		$parent_id = $this->parent_id();

		// Lock the table
		$this->lock();		
		
		// It's best that the target isn't changed so the update will work ok (works best after the lock)
		$target->reload();
		$this->reload();
		
		// we also move between scopes
		$targetscope = $target->scope();
		$currentscope = $this->scope();
		// Catch any database or other exceptions and unlock
		try
		{
			// Stop the current node being moved into a descendant or itself or disallow if target is root and not allowed
			if 	(
					$this->primary_key() === $target->primary_key()
					OR $target->is_descendant($this)
					OR ($allow_root_target === FALSE AND $target->is_root())
				)
			{
				$this->unlock();
				
				return FALSE;
			}
			
			$left_offset = ( ($use_left_column) ? $target->left() : $target->right() ) + $left_offset;
			$level_offset = $target->level() - $this->level() + $level_offset;
			$size = $this->size();
			
			// Now we create a gap to place this item in that gap
			$this->create_space($left_offset, $size, $targetscope);
			
			// Why??
			$this->reload();
			
			$offset = ($left_offset - $this->left());
			
			// Update the childeren for the new place in the tree
			//the SET's in this query cannot be right by the syntax of it
			/*$this->db->update(
					$this->table_name,
					array(
						$this->left_column .  ' = ' . $this->left_column . ' + ' . intval($offset),
						$this->right_column .  ' = ' . $this->right_column . ' + ' . intval($offset),
						$this->level_column . ' = ' . $this->level_column . ' + ' . intval($level_offset),
						$this->scope_column .' = ' . intval($targetscope)
					),
					array(
						$this->left_column . ' >=' => $this->left(),
						$this->right_column . ' <=' => $this->right(),
						$this->scope_column => $targetscope
					)
			);*/
			$this->db->query(
					'UPDATE '.$this->table_name.
					' SET '
						.$this->left_column.' = '.$this->left_column.' + '.intval($offset) . ', '
						.$this->right_column.' = '.$this->right_column.' + '.intval($offset) . ', '
						.$this->level_column.' = '.$this->level_column.' + '.intval($level_offset) . ', '
						.$this->scope_column.' = '.intval($targetscope)
					.' WHERE '
						.$this->left_column.' >= '.$this->left()
						.' AND ' . $this->right_column.' <= '.$this->right()
						.' AND ' . $this->scope_column . ' = ' . intval($currentscope)
			);

			// Now we close the old gap
			$this->delete_space($this->left(), $size, $currentscope);
			
			//after the update reload this
			$this->reload();
			
			// Change MPTT values to new place in the tree
			if ($level_offset == 1)
				$this->{$this->parent_column} = $target->primary_key();
			
			/*$this->{$this->level_column} += intval($level_offset); // Gets possibly a new level
			$this->{$this->left_column} = $left_offset;
			$this->{$this->right_column} = $left_offset + 1;*/
		}
		catch (Exception $e) 
		{
			// Unlock table and re-throw exception
			$this->unlock();
			
			throw $e;
		}
		
		// all went well so save the parent_id if changed
		if ($parent_id != $this->parent_id()) 
		{
			$this->{$this->parent_column} = $parent_id;
			$this->{$this->scope_column} = $targetscope;
			$this->_old_parentid = $parent_id; // So the following function will be handled correctly
			
			$this->save();
		}
		
		$this->unlock();
		
		return $this;
	}
	
	/**
	 * Return the next available scope
	 * 
	 * @access protected
	 * @return int
	 */
	protected function get_next_scope()
	{
		$scope = 1;
		
		$query = $this->db
				->from($this->table_name)
				->select('DISTINCT ' . $this->scope_column)
				->orderby($this->scope_column, 'DESC')
				->limit(1)
				->get();
		
		//TODO: this needs testing
		if (!empty($query) && $query->count() > 0) {
			$qrow = $query->current();
			
			$scope = intval($qrow->scope) + 1;
		}
		
		return $scope;
	}
	
	public function get_nodes_where($where, $limit = NULL) {
		$result = self::factory($this->object_name)
						->where($where)
						->orderby($this->scope_column, 'ASC')
						->orderby($this->left_column, 'ASC')
						->orderby($this->primary_key, 'ASC');
		
		if (!empty($limit))
			$result->limit($limit);
		
		return $result->find_all();
	}
	
	/**
	 * Checks if a scope value is available for use.
	 * 
	 * @access protected
	 * @return boolean
	 */
	protected function scope_available($scope)
	{
		$scopecount = $this->db->count_records($this->table_name, array($this->scope_column => intval($scope)));
		
		return ($scopecount <= 0);
	}
	
	/**
	 * Inserts this node as new root node in a new scope.
	 * 
	 * @access public
	 * @param object $scope [optional]
	 * @return mixed
	 */
	public function insert_as_new_root($scope = NULL)
	{
		// Insert should only work on new nodes.. if its already it the tree it needs to be moved!
		if ($this->loaded === TRUE)
			return FALSE;
		
		return $this->save_as_root($scope);
	}
	
	/**
	 * Inserts the current node as a new node at the left of the first child of the target.
	 *
	 * $parent = 12;
	 *
	 * $new = ORM::factory('table');
	 * $new->name = 'New Node';
	 * $new->insert_as_first_child($parent);
	 *
	 * @access public
	 * @param $target object | integer Node object or ID.
	 * @return void
	 **/
	public function insert_as_first_child($target)
	{
		if (!($target instanceof $this))
			$target = self::factory($this->object_name, $target);
		
		$this->{$this->parent_column} = ($target->loaded === TRUE) ? $target->primary_key() : NULL;
		
		// Example : left = 1, right = 32

		// Values for the new node
		// Example : left = 2, right = 3
		
		return $this->insert_node($target, $this->left_column, 1, 1);
	}
	
	/**
	 * Inserts the current node as a new node at the right of the last child of the target.
	 *
	 * $parent = 12;
	 *
	 * $new = ORM::factory('table');
	 * $new->name = 'New Node';
	 * $new->insert_as_last_child($parent);
	 *
	 * @access public
	 * @param $target object | integer Node object or ID.
	 * @return void
	 **/
	public function insert_as_last_child($target)
	{
		if (!($target instanceof $this))
			$target = self::factory($this->object_name, $target);
		
		$this->{$this->parent_column} = ($target->loaded === TRUE) ? $target->primary_key() : NULL;
		
		// Example : left = 1, right = 32

		// Values for the new node
		// Example : left = 32, right = 33
		
		return $this->insert_node($target, $this->left_column, 0, 1);
	}

	/**
	 * Inserts the current node as a new node as a previous sibling of the target.
	 *
	 * $target = 12;
	 *
	 * $new = ORM::factory('table');
	 * $new->name = 'New Node';
	 * $new->insert_as_prev_sibling($target);
	 *
	 * @access public
	 * @param $target object | integer Node object or ID.
	 * @return void
	 **/
	public function insert_as_prev_sibling($target)
	{
		if (!($target instanceof $this))
			$target = self::factory($this->object_name, $target);
		
		$this->{$this->parent_column} = ($target->loaded === TRUE) ? $target->parent_id() : NULL;
		
		// Example : left = 13, right = 14
		//		target: left = 7, right = 8

		// Values for the new node
		// Example : left = 7, right = 8
		//		target: left = 9, right = 10
		
		return $this->insert_node($target, $this->left_column, 0, 0);
	}

	/**
	 * Inserts the current node as a new node as a next sibling of the target.
	 *
	 * $target = 12;
	 *
	 * $new = ORM::factory('table');
	 * $new->name = 'New Node';
	 * $new->insert_as_next_sibling($target);
	 *
	 * @access public
	 * @param $target object | integer Node object or ID.
	 * @return void
	 **/
	public function insert_as_next_sibling($target)
	{
		if (!($target instanceof $this))
			$target = self::factory($this->object_name, $target);
		
		$this->{$this->parent_column} = ($target->loaded === TRUE) ? $target->parent_id() : NULL;
		
		// Example : left = 13, right = 14
		//		target: left = 7, right = 8

		// Values for the new node
		// Example : left = 9, right = 10
		//		target: left = 7, right = 8
		
		return $this->insert_node($target, $this->left_column, 1, 0);
	}
	
	/**
	 * Loads an array of values into into the current object. Overloads to store old parent_id so it can detect parent changes.
	 *
	 * @chainable
	 * @param   array  values to load
	 * @return  ORM
	 */
	public function load_values(array $values)
	{
		$returnval = parent::load_values($values);
		
		$this->_old_parentid = $this->parent_id();
		
		return $returnval;
	}
	
	/**
	 * Overloaded save method
	 *
	 * @access public
	 * @return mixed
	 **/
	public function save()
	{
		if ($this->loaded === TRUE)
		{
			if ($this->_old_parentid != $this->parent_id()) {
				$this->_old_parentid = $this->parent_id(); //so the following function will be handled correctly
				
				if (empty($this->_old_parentid))
					return $this->save_as_root();
				else
					return $this->move_to_last_child($this->parent_id());
			} else
				return parent::save();
		} elseif (intval($this->parent_id()) <= 0) { // if parent_id is empty or not a number and does not already exists, save as new root
			return $this->save_as_root();
		} else {
			return $this->insert_as_last_child($this->parent_id());
		}
		
		// it never makes it till here, but the structure of this function with else makes more sence to read the code
		return FALSE;
	}
	
	/**
	 * Removes a node and descendants if specified.
	 *
	 * @access public
	 * @return void
	 **/
	public function delete($id = NULL)
	{
		// Lock the table
		$this->lock();
		
		// Only existing nodes can be deleted
		if (!$this->loaded)
			return FALSE;
		
		$childnodes = $this->get_nodes_where(array(
											$this->parent_column => $this->primary_key(),
											$this->scope_column => $this->scope()
										));
		
		if (!empty($childnodes) && count($childnodes) > 0)
		foreach ($childnodes as $childnode)
		{
			$this->delete();
		}
		
		$left = $this->left();
		$size = $this->size();
		$scope = $this->scope();
		
		try
		{
			parent::delete();
			
			// Close the gap
			$this->delete_space($left, $size, $scope);
		}
		catch (Exception $e)
		{
			//first unlock the table
			$this->unlock();
			
			throw $e;
		}

		// Unlock the table.
		$this->unlock();
		
		return $this->clear();
	}

	/**
	 * Overloads the select_list method to
	 * support indenting.
	 *
	 * @param $key string First table column
	 * @param $val string Second table column
	 * @param $indent string Use this character for indenting
	 * @return void
	 **/
	public function select_list($key = NULL, $val = NULL, $indent = NULL)
	{
		if (is_string($indent))
		{
			if ($key === NULL)
			{
				// Use the default key
				$key = $this->primary_key;
			}
	
			if ($val === NULL)
			{
				// Use the default value
				$val = $this->primary_val;
			}
			
			$this->db->orderby(array($this->scope_column => 'ASC', $this->left_column => 'ASC'));
			
			$result = $this->load_result(TRUE);
			
			$basedepth = $this->level();
			
			$array = array();
			foreach ($result as $row)
			{
				$array[$row->$key] = str_repeat($indent, intval($row->{$this->level_column})).$row->$val;
			}
			
			return $array;
		}

		return parent::select_list($key, $val);
	}

	/**
	 * Overloads the select_list method to
	 * support indenting. But this one limits as well, so the descendant and itself won't be shown.
	 *
	 * @param $key string First table column
	 * @param $val string Second table column
	 * @param $indent string Use this character for indenting
	 * @return void
	 **/
	public function select_list_limited($key = NULL, $val = NULL, $indent = NULL)
	{
		if (is_string($indent))
		{
			if ($key === NULL)
			{
				// Use the default key
				$key = $this->primary_key;
			}
	
			if ($val === NULL)
			{
				// Use the default value
				$val = $this->primary_val;
			}
			
			$basedepth = $this->level();
			$this->db->where($this->primary_key . ' !=', $this->primary_key());
			if ($this->has_children())
			{
				$this->db->where($this->left_column . ' <', $this->left());
				$this->db->orwhere($this->right_column . ' >', $this->right());
				$this->db->orwhere($this->scope_column . ' !=', $this->scope());
			}
			
			$this->db->orderby(array($this->scope_column => 'ASC', $this->left_column => 'ASC'));
			$result = $this->load_result(TRUE);
			
			
			$array = array();
			foreach ($result as $row)
			{
				$array[$row->$key] = str_repeat($indent, intval($row->{$this->level_column})).$row->$val;
			}
			
			return $array;
		}

		return parent::select_list($key, $val);
	}
	

	
	/**
	 * Move to First Child
	 *
	 * This moves the current node to the first child of the target node.
	 *
	 * @param $target object | integer Target Node
	 * @return void
	 **/
	public function move_to_first_child($target)
	{
		if (!($target instanceof $this))
			$target = self::factory($this->object_name, $target);
		
		$this->{$this->parent_column} = ($target->loaded === TRUE) ? $target->primary_key() : NULL;
		
		return $this->move($target, TRUE, 1, 1, TRUE);
	}
	
	/**
	 * Move to Last Child
	 *
	 * This moves the current node to the last child of the target node.
	 *
	 * @param $target object | integer Target Node
	 * @return void
	 **/
	public function move_to_last_child($target)
	{
		if (!($target instanceof $this))
			$target = self::factory($this->object_name, $target);
		
		$this->{$this->parent_column} = ($target->loaded === TRUE) ? $target->primary_key() : NULL;
		
		return $this->move($target, FALSE, 0, 1, TRUE);
	}
	
	/**
	 * Move to Previous Sibling.
	 *
	 * This moves the current node to the previous sibling of the target node.
	 *
	 * @param $target object | integer Target Node
	 * @return void
	 **/
	public function move_to_prev_sibling($target)
	{
		if (!($target instanceof $this))
			$target = self::factory($this->object_name, $target);
		
		$this->{$this->parent_column} = ($target->loaded === TRUE) ? $target->parent_id() : NULL;
		
		return $this->move($target, TRUE, 0, 0, TRUE);
	}
	
	/**
	 * Move to Next Sibling.
	 *
	 * This moves the current node to the next sibling of the target node.
	 *
	 * @param $target object | integer Target Node
	 * @return void
	 **/
	public function move_to_next_sibling($target)
	{
		if (!($target instanceof $this))
			$target = self::factory($this->object_name, $target);
		
		$this->{$this->parent_column} = ($target->loaded === TRUE) ? $target->parent_id() : NULL;
		
		return $this->move($target, FALSE, 1, 0, FALSE);
	}
	
	/**
	 *
	 * @access public
	 * @param $column - Which field to get.
	 * @return mixed
	 **/
	public function __get($column)
	{
		switch ($column)
		{
			case 'parent':
				return $this->parent();
			case 'parents':
				return $this->parents();
			case 'children':
				return $this->children();
			case 'first_child':
				return $this->children(FALSE, 'ASC', 1);
			case 'last_child':
				return $this->children(FALSE, 'DESC', 1);
			case 'siblings':
				return $this->siblings();
			case 'root':
				return $this->root();
			case 'roots':
				return $this->roots();
			case 'leaves':
				return $this->leaves();
			case 'descendants':
				return $this->descendants();
			case 'fulltree':
				return $this->fulltree();
			case 'tree':
				return $this->fulltree(TRUE);
			default:
				return parent::__get($column);
		}
	}
	
	/**
	 * Verify the tree is in good order 
	 * 
	 * This functions speed is irrelevant - its really only for debugging and unit tests
	 * 
	 * @todo Look for any nodes no longer contained by the root node.
	 * @todo Ensure every node has a path to the root via ->parents();
	 * @access public
	 * @return boolean
	 **/
	public function verify_tree()
	{
		if ( ! $this->is_root())
			throw new Exception('verify_tree() can only be used on root nodes');
		
		$end = $this->{$this->right_column};

		// Look for nodes no longer contained by the root node.
		$extra_nodes = self::factory($this->object_name)->where($this->left_column.' > ', $end)->orwhere($this->right_column.' > ', $end)->find_all();
		
		// Out of bounds.
		if ($extra_nodes->count() > 0)
			return FALSE;
		
		$i = 0;
		
		while ($i < $end)
		{
			$i++;
			$nodes = self::factory($this->object_name)->where($this->left_column, $i)->orwhere($this->right_column, $i)->find_all();
			
			// 2 or more nodes have the same left or right value.
			if ($nodes->count() != 1)
				return FALSE;
			
			// The left value is bigger than the right, impossible!
			if ($nodes->current()->{$this->left_column} >= $nodes->current()->{$this->right_column})
				return FALSE;
			
			// Tests that only apply to non root nodes. 
			if ( ! $nodes->current()->is_root())
			{
				$parent_level = $nodes->current()->parent->{$this->level_column};
				$our_level = $nodes->current()->{$this->level_column};
				
				if ($parent_level + 1 != $our_level)
					return FALSE;
			}
		}
		
		return TRUE;
	}
	
	public function rebuild_tree() 
	{
		$rootnodes = $this->roots();
		
		if (!empty($rootnodes) && count($rootnodes) > 0)
		foreach ($rootnodes as $rootnode)
		{
			$counter = 0;
			
			$this->rebuild_node($rootnode, $counter, 0);
		}
		
		return TRUE;
	}
	
	public function rebuild_node(&$node, &$counter = null, $level = null)
	{
		if (empty($counter) || intval($counter) <= 0)
			$counter = 0;
		if (empty($level) || intval($level) <= 0)
			$level = 0;
		if (!($node instanceof $this))
			$node = self::factory($this->object_name, $node);
		
		// Only existing nodes can be rebuild
		if (!$node->loaded)
			return FALSE;
		
		$counter++;
		$level++;
		
		$childnodes = $this->get_nodes_where(array(
											$this->parent_column => $node->primary_key(),
											$this->scope_column => $node->scope()
										));
		
		$node->{$this->left_column} = $counter;
		$node->{$this->level_column} = $level;
		
		if (!empty($childnodes) && count($childnodes) > 0)
		foreach ($childnodes as $childnode)
		{
			$this->rebuild_node($childnode, $counter, $level);
		}
		
		$counter++;
		
		$node->{$this->right_column} = $counter;
		$node->save();
		
		return TRUE;
	}
} // END class ORM_MPTT_Core