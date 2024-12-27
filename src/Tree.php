<?php


namespace Oliva\Utils\Tree;

use RuntimeException,
	Iterator,
	IteratorAggregate,
	Traversable,
	ReflectionClass;
use Oliva\Utils\Tree\Node\INode,
	Oliva\Utils\Tree\Node\NodeBase,
	Oliva\Utils\Tree\Iterator\TreeIterator,
	Oliva\Utils\Tree\Iterator\TreeSimpleFilterIterator,
	Oliva\Utils\Tree\Iterator\TreeFilterIterator,
	Oliva\Utils\Tree\Iterator\TreeCallbackFilterIterator;


/**
 * Tree.
 *
 * A basic tree. Supports searching, filtering (via iterators) and linear transformations.
 *
 *
 * @author Andrej Rypak <xrypak@gmail.com>
 */
class Tree implements ITree, IteratorAggregate
{
	/**
	 *
	 * @var INode
	 */
	protected $root;


	public function __construct(?INode $root = NULL)
	{
		if ($root !== NULL) {
			$this->setRoot($root);
		}
	}


	/**
	 * Set the root node.
	 *
	 *
	 * @param INode $root
	 * @return Tree
	 */
	public function setRoot(INode $root)
	{
		$this->root = $root;
		return $this;
	}


	/**
	 * Return the root node.
	 *
	 *
	 * @return INode
	 */
	public function getRoot()
	{
		return $this->root;
	}


	/**
	 * Returns RECURSIVE tree node iterator. Can also iterate non-recursively.
	 *
	 * @see TreeIterator for iteration modes
	 *
	 *
	 * @param string|NULL $recursion
	 * @return Traversable
	 */
	public function getIterator($recursion = TreeIterator::BREADTH_FIRST_RECURSION): Traversable
	{
		$root = $this->getRoot();

		if ($root instanceof NodeBase) {

			// if the root is a NodeBase implementation, we can use it's built in iteration support
			return $root->getIterator($recursion);
		}

		if ($root instanceof IteratorAggregate) {

			//NOTE: I'm not completely sure this behaviour can be supported. Are we losing the $recursion parameter in the underlying implementation?
			return $root->getIterator($recursion);
		}

		if ($root instanceof Iterator) {

			//NOTE: I'm not completely sure this behaviour can be supported. We are losing the $recursion parameter.
			return $root;
		}

		throw new RuntimeException('Cannot retrieve an iterator for the root node. To use this feature, the root node has to implement getIterator() method or be an iterator itself. This can be achieved by descending the node from NodeBase or implement IteratorAggregate or Iterator intefaces.');
	}


	/**
	 * Returns iterator with conditions to compare nodes against.
	 *
	 * $i = $tree->getFilterIterator(['id' => 5, 'position' => '002003001'], TreeFilterIterator::MODE_AND, TreeFilterIterator::MODE_OR); // the default filtering modes
	 * $i = $tree->getFilterIterator(['id' => [5, 6, 145], 'colour' => 'red']);
	 *
	 *
	 * @param array $conditions
	 * @param string $mode
	 * @param string|NULL $recursion
	 * @return TreeFilterIterator
	 */
	public function getFilterIterator(array $conditions, $mode = TreeFilterIterator::MODE_AND, $recursion = TreeIterator::BREADTH_FIRST_RECURSION)
	{
		return new TreeFilterIterator($this->getIterator($recursion), $conditions, $mode);
	}


	/**
	 * Return iterator with filtering callback.
	 * The first argument passed to the callback is the node, the second is the index.
	 *
	 * 	$i = $tree->getFilteringCallbackIterator(function(\Oliva\Tree\NodeBase $node, $index) {
	 * 		try {
	 * 			return $index === '002' || $node->id === 4;
	 * 		} catch (MemberAccessException $e) {
	 * 			return FALSE;
	 * 		}
	 * 	});
	 *
	 *
	 * @param callable $filteringCallback
	 * @param string|NULL $recursion
	 * @param ...
	 * @return TreeCallbackFilterIterator
	 */
	public function getCallbackFilterIterator(callable $filteringCallback, $recursion = TreeIterator::BREADTH_FIRST_RECURSION/* , ...$params */)
	{
//		return new TreeCallbackFilterIterator($this->getIterator($recursion), $filteringCallback, ...$params); // this would work on PHP 5.6
		// and the following is the PHP 5.4 workaround...
		$ref = new ReflectionClass(TreeCallbackFilterIterator::class);
		return $ref->newInstanceArgs(array_merge([$this->getIterator($recursion), $filteringCallback], array_slice(func_get_args(), 2)));
	}


	/**
	 * Find a node by specific key/value pair.
	 *
	 *
	 * @param scalar $key
	 * @param mixed $val
	 * @param string|NULL $recursion
	 * @return INode|NULL
	 */
	public function find($key, $val, $recursion = TreeIterator::BREADTH_FIRST_RECURSION)
	{
		$it = new TreeSimpleFilterIterator($this->getIterator($recursion), $key, $val);
		$it->rewind();
		$node = $it->current(); // the first element, FALSE if empty
		return $node instanceof INode ? $node : NULL;
	}


	/**
	 * Alias for getDepthFirst().
	 * Does not preserve node children indices.
	 *
	 *
	 * @return array
	 */
	public function getLinear()
	{
		return $this->getBreadthFirst();
	}


	/**
	 * Returns an array of nodes in depth-first manner. Useful for grids, lists etc.
	 * Does not preserve node children indices.
	 *
	 * Note: array_merge([$tree->getRoot()], iterator_to_array($tree->getIterator(TreeIterator::BREADTH_FIRST_RECURSION))) === $tree->getBreadthFirst()
	 *
	 *
	 * @return array
	 */
	public function getDepthFirst()
	{
		return self::depthFirstTransform($this->root);
	}


	/**
	 * Returns an array of nodes in bredth-first manner.
	 * Does not preserve node children indices.
	 *
	 *
	 * @return array
	 */
	public function getBreadthFirst()
	{
		return self::breadthFirstTransform($this->root);
	}


	/**
	 * Returns an array of nodes in depth-first manner. Useful for grids, lists etc.
	 * Does not preserve node children indices.
	 *
	 *
	 * @return array
	 */
	public static function depthFirstTransform(INode $root)
	{
		return self::df($root);
	}


	/**
	 * Returns an array of nodes in bredth-first manner.
	 * Does not preserve node children indices.
	 *
	 *
	 * @return array
	 */
	public static function breadthFirstTransform(INode $root)
	{
		return array_merge(array($root), self::bf($root));
	}


	protected static function df(INode $node)
	{
		$list = array($node);
		foreach ($node->getChildren() as $child) {
			$list = array_merge($list, self::df($child));
		}
		return $list;
	}


	protected static function bf(INode $node)
	{
		$list = $node->getChildren();
		foreach ($list as $child) {
			$list = array_merge($list, self::bf($child));
		}
		return $list;
	}

}
