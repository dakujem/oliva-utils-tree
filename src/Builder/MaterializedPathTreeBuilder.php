<?php


namespace Oliva\Utils\Tree\Builder;

use RuntimeException;
use Oliva\Utils\Tree\Node\INode;


/**
 * Materialized path tree builder.
 * Builds data from a linear data structure, where each node holds its
 * position within the tree in its hierarchy member, e.g. the Materialized Path Tree data model.
 *
 *
 * NOTE:	When a connection to the root node or to any ancestor is missing,
 * 			the builder will automatically create empty stub nodes and bridge the gap - connect the node through
 * 			these stub nodes all the way to the root node or other nearest ancestor.
 * 			A custom hierarchy getter can be used to cut a certain portion of the string for building sub-trees
 * 			that need not be bridged all the way to the root.
 *
 * NOTE:	When the hierarchy contains IDs or other reference values of its parents only,
 * 			reference to the current node needs to be added to the end for the builder to work. This can be done
 * 			using MaterializedPathTreeBuilderFactory::createDelimitedReferenceVariant() method.
 * 			@see MaterializedPathTreeBuilderFactory
 *
 *
 * @author Andrej Rypak <xrypak@gmail.com>
 */
class MaterializedPathTreeBuilder extends TreeBuilder implements ITreeBuilder
{
	/**
	 * The default hierarchy member.
	 * @var string
	 */
	public static $hierarchyDefault = 'position';

	/**
	 * The delimiter default. Can be integer for fixed-length hierarchy or string for delimited hierarchy.
	 * @var int|string
	 */
	public static $delimiterDefault = 3;

	/**
	 * The index default. Can be string for data member or a callable.
	 * @var callable|string
	 */
	public static $indexDefault = NULL;

	/**
	 * The Node's hierarchy value getter.
	 * @var array[$callable, $parameter]
	 */
	protected $hierarchyGetter;

	/**
	 * The delimiting processor.
	 * @var array[$callable, $delimiterParameter]
	 */
	protected $delimitingProcessor;

	/**
	 * The index processor - will produce indices for nodes.
	 * @var callable
	 */
	protected $indexProcessor;

	/**
	 * The sorting subroutine used for children nodes sorting.
	 * @var callable|NULL
	 */
	protected $sorting;


	public function __construct($hierarchy = NULL, $delimiter = NULL, $index = NULL, $sortNodes = FALSE)
	{
		parent::__construct();
		$this->setHierarchy($hierarchy !== NULL ? $hierarchy : static::$hierarchyDefault);
		$this->setDelimiter(!$delimiter ? static::$delimiterDefault : $delimiter); // intentionally operator ! to match NULL, FALSE, 0, "0" and ""
		$this->setIndex($index !== NULL ? $index : static::$indexDefault);
		$this->setSorting($sortNodes);
	}


	/**
	 * Build the tree from linear data.
	 * The root node contains no data (unless data item with NULL position is provided) and is always created.
	 *
	 * Note:	duplicity in hierarchy member of items is not detected and may result in unexpected behaviour.
	 * 			Duplicit nodes actually replace the ones comming before them.
	 *
	 *
	 * @param array|Traversable $data traversable data containing node data items
	 * @return INode the root node
	 */
	public function build($data)
	{
		// data check
		$this->checkData($data);

		/* @var $nodeCache INode[] cache of already processed nodes */
		$nodeCache = [];
		foreach ($data as $item) {

			// NOTE: I'm using the word "position" in meaning of "node's hierarchy value"
			$itemPosition = $this->getCurrentHierarchy($item);

			if (!isset($nodeCache[$itemPosition])) {
				// create a new node and insert it into the node cache
				$nodeCache[$itemPosition] = $currentNode = $this->createNode($item);

				// get the parent's position
				$parentPos = $itemPosition !== NULL ? $this->getParentHierarchy($itemPosition) : NULL;

				// insert node into the tree
				if ($itemPosition !== NULL && isset($nodeCache[$parentPos])) {
					// Parent has already been processed, link the child
					$nodeCache[$parentPos]->addChild($currentNode, $this->getChildIndex($itemPosition, $currentNode));
				} elseif ($itemPosition !== NULL) {
					// Parent not processed yet, bridge the gap between the current node and the nearest processed ancestor:
					//
					// When processing a node that does not connect directly to an already processed parent node (in cache),
					// we need to create stub nodes (empty) and connect through them until we connect to a node in cache:
					// currentNode -> stub -> stub -> ... -> anyCachedNode
					// The bridging is terminated when it reaches the root level or a cached node.
					// If the data provided is consistent, the stub nodes will be replaced by well-formed nodes later on.
					$stubParentPos = $parentPos;
					$childPosition = $itemPosition;
					$childNode = $currentNode;
					do {
						$nodeCache[$stubParentPos] = $stubParent = $this->createNode();
						$stubParent->addChild($childNode, $this->getChildIndex($childPosition, $childNode !== $currentNode ? NULL : $currentNode ));
						$childNode = $stubParent;
						$childPosition = $stubParentPos;
						$stubParentPos = $stubParentPos !== NULL ? $this->getParentHierarchy($stubParentPos) : NULL;
					} while (!isset($nodeCache[$stubParentPos]));
					// connect to the previously processed node,
					// EXCEPT when the above cycle terminated due to reaching the root
					if ($stubParent !== $nodeCache[$stubParentPos]) {
						$nodeCache[$stubParentPos]->addChild($childNode, $this->getChildIndex($childPosition, NULL));
					}
				} else {
					// root found, nothing to do here
				}
			} else {
				// the node already exists in the node cache (due to data failure or gap bridging)
				// replace the (stub) node and copy node relations
				$nodeCache[$itemPosition] = $this->replaceNode($nodeCache[$itemPosition], $item, $itemPosition);
			}
		}

		// the root
		$root = isset($nodeCache[NULL]) ? $nodeCache[NULL] : NULL;

		// post process nodes (sort)
		$this->sortChildren($root);

		return $root;
	}


	/**
	 * Set how to get the hierarchy value.
	 * The accepted value types are:
	 * 		- string: node's member, to avoid collisions with callable functions, use "@" prefix
	 * 		- callable: any callable
	 *
	 * The callable receives arguments:
	 * 		- 1. mixed node data
	 * The callable must return the hierarchy (member) value.
	 *
	 *
	 * @param string|callable $hierarchy
	 * @return self fluent
	 * @throws RuntimeException
	 */
	public function setHierarchy($hierarchy)
	{
		if ($this->isAcceptableCallback($hierarchy)) {
			$this->hierarchyGetter = [$hierarchy, NULL];
		} elseif (is_string($hierarchy)) {
			$this->hierarchyGetter = [[$this, 'getMember'], $hierarchy];
		} else {
			throw new RuntimeException(sprintf('Invalid hierarchy member/getter of type %s provided. Either provide a string containing the name of the hierarchy member or a callable function that will return the node\'s hierarchy member value. Global namespace functions and objects have to be prefixed by a backslash "\\" character.', is_object($hierarchy) ? get_class($hierarchy) : gettype($hierarchy)), 4);
		}
		return $this;
	}


	/**
	 * Set the subroutine for parsing the hierarchy string - delimiting processor.
	 * The accepted value types are:
	 * 		- string:	a character delimiter, usually one of: .,|/-
	 * 					--> use for character delimiting
	 * 		- int:		a number och characters for each level
	 * 					--> use for fixed-length hierarchy delimiting
	 * 		- callable:	any callable
	 * 					--> use for custom delimiting
	 *
	 * The callable receives arguments:
	 * 		- 1. string current node's hierarchy value
	 * The callable must return the hierarchy value of the parent node for given current node's hierarchy.
	 *
	 *
	 * @param string|callable $delimiter
	 * @return self fluent
	 * @throws RuntimeException
	 */
	public function setDelimiter($delimiter)
	{
		if (is_numeric($delimiter)) {
			$this->delimitingProcessor = [[$this, 'getParentPositionFixedLength'], $delimiter];
		} elseif ($this->isAcceptableCallback($delimiter)) {
			$this->delimitingProcessor = [$delimiter, NULL];
		} elseif (is_string($delimiter)) {
			$this->delimitingProcessor = [[$this, 'getParentPositionDelimited'], $delimiter];
		} else {
			throw new RuntimeException(sprintf('Invalid delimiter of type %s provided. Either provide an integer for fixed-length hierarchy delimiting, a string containing a delimiting character or a callable function that will process the node\'s hierarchy member value.', is_object($delimiter) ? get_class($delimiter) : gettype($delimiter)), 4);
		}
		return $this;
	}


	/**
	 * Set how to calculate indices.
	 * The accepted value types are:
	 * 		- NULL: do not use any processor, use the hierarchy as index
	 * 		- string: node's member
	 * 		- callable: any callable; when using global namespace functions or global namespace object methods, prefix them with a backslash \ character
	 *
	 * The callable receives arguments:
	 * 		- 1. string hierarchy
	 * 		- 2. INode the node
	 * The callable must return a unique index or NULL that will be used as argument to INode::addChild() call.
	 * When returning NULL, standard array indexing is used.
	 *
	 *
	 * @param string|callable $processor
	 * @return self fluent
	 * @throws RuntimeException
	 */
	public function setIndex($processor = NULL)
	{
		if ($processor !== NULL && !is_callable($processor) && !is_string($processor)) {
			throw new RuntimeException(sprintf('Invalid index processor of type %s provided. Provide a node member name that will be used as an index for the processed node or a callable function that will return the index.', is_object($processor) ? get_class($processor) : gettype($processor)), 4);
		}
		$this->indexProcessor = $processor;
		return $this;
	}


	/**
	 * Set the sorting subroutine used for sorting of child nodes.
	 * This is the only post processing subroutine and is only provided for convenience,
	 * as sorting is often required.
	 *
	 * The callable receives arguments:
	 * 		- 1. array $nodes
	 * The callable can process the nodes in any way appropriate.
	 *
	 *
	 * @param bool|callable $sorting
	 * @return self fluent
	 * @throws RuntimeException
	 */
	public function setSorting($sorting)
	{
		if ($sorting === TRUE) {
			$this->sorting = [$this, 'sortNodes'];
		} elseif (!$sorting) {
			$this->sorting = NULL;
		} elseif (is_callable($sorting)) {
			$this->sorting = $sorting;
		} else {
			throw new RuntimeException(sprintf('Invalid sorting processor of type %s provided. Provide a valid callable function that will sort array of nodes or use TRUE to use default sorting or FALSE to not use any sorting.', is_object($sorting) ? get_class($sorting) : gettype($sorting)), 4);
		}
		return $this;
	}


	/**
	 * Get the value of the hierarchy for a given node's data.
	 *
	 *
	 * @param mixed $data arbitrary node's data
	 * @return string
	 */
	protected function getCurrentHierarchy($data)
	{
		$str = call_user_func($this->hierarchyGetter[0], $data, $this->hierarchyGetter[1]);
		if ($str === FALSE || $str === null || strlen((string)$str) === 0) {
			return NULL;
		}
		return $str;
	}


	/**
	 * Get hierarchy (or any unique identifier) of a parent's node.
	 * This method is used to parse a parent's portion of a given hierarchy.
	 *
	 *
	 * @param string $currentHierarchy
	 * @return string
	 */
	protected function getParentHierarchy($currentHierarchy)
	{
		$str = call_user_func($this->delimitingProcessor[0], $currentHierarchy, $this->delimitingProcessor[1]);
		if ($str === FALSE || strlen($str) === 0) {
			return NULL;
		}
		return $str;
	}


	/**
	 * Method used as the default callable for the string-delimited hierarchies.
	 * @internal set in self::setDelimiter()
	 *
	 *
	 * @param string $hierarchy
	 * @param string $delimiter
	 * @return string
	 */
	protected function getParentPositionDelimited($hierarchy, $delimiter)
	{
		return substr($hierarchy, 0, strrpos($hierarchy, $delimiter)); // truncate the string at the last delimiter
	}


	/**
	 * Method used as the default callable for the fixed-length hierarchies.
	 * @internal set in self::setDelimiter()
	 *
	 *
	 * @param string $hierarchy
	 * @param int $length
	 * @return string
	 */
	protected function getParentPositionFixedLength($hierarchy, $length)
	{
		// simply discard the last $length-count characters from the end of the hierarchy
		return substr($hierarchy, 0, -$length);
	}


	/**
	 * Calculate the index for the node. Either use node's data or the hierarchy string.
	 *
	 *
	 * @param string $hierarchy
	 * @param INode $node is NULL for stub nodes
	 * @return int|string index for node
	 */
	protected function getChildIndex($hierarchy, ?INode $node = NULL)
	{
		if ($this->isAcceptableCallback($this->indexProcessor)) {
			return call_user_func($this->indexProcessor, $hierarchy, $node);
		} elseif ($node !== NULL && is_string($this->indexProcessor)) {
			return $this->getMember($node, $this->indexProcessor);
		}
		return $hierarchy;
	}


	/**
	 * Sorts INode's children by key.
	 *
	 *
	 * @param INode $node the root node
	 * @return void
	 */
	protected function sortChildren(INode $node)
	{
		if ($this->sorting !== NULL) {
			$children = $node->getChildren();
			$node->removeChildren();
			$sortedNodes = call_user_func($this->sorting, $children);
			foreach ($sortedNodes as $index => $child) {
				$this->sortChildren($child); // recursion
				$node->addChild($child, $index);
			}
		}
		return $this;
	}


	/**
	 * Sorts nodes by key using ksort().
	 * @internal set in self::setSorting()
	 *
	 *
	 * @param INode[] $nodes array of INode objects
	 * @return INode[] sorted array of INode objects
	 */
	protected function sortNodes(array $nodes)
	{
		ksort($nodes);
		return $nodes;
	}


	/**
	 * Replaces a node with a new one. Copies all its relationships and resets its child index if it has a parent.
	 * @internal
	 *
	 *
	 * @param INode $source
	 * @param mixed $data data for the node
	 * @param string $hierarchy the hierarchy member string value
	 * @return INode
	 */
	protected function replaceNode(INode $source, $data, $hierarchy)
	{
		/* @var $destination INode */
		$destination = $this->createNode($data);
		foreach ($source->getChildren() as $index => $child) {
			$destination->addChild($child, $index);
		}
		$parent = $source->getParent();
		if ($parent instanceof INode && $parent !== $source) {
			$parent->removeChild($parent->getChildIndex($source));
			$parent->addChild($destination, $this->getChildIndex($hierarchy, $destination));
		}
		return $destination;
	}

}
