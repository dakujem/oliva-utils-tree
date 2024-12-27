<?php


namespace Oliva\Utils\Tree\Node;


/**
 * Bare INode implementation trait.
 *
 * Use in a class to implement the INode interface.
 * @see INode
 *
 *
 * @author Andrej Rypak <xrypak@gmail.com>
 */
trait BareNodeTrait
{
	protected $children = [];
	protected $parent = NULL;


	/**
	 * Set the node's direct parent.
	 *
	 * Note:	Calling $node->setParent($parent) DOES NOT ADD the node to its parent's children array!
	 * 			To do that, call $parent->addChild($node) instead.
	 *
	 *
	 * @param INode $node
	 * @return NodeBase fluent
	 */
	public function setParent(?INode $node = NULL)
	{
		$this->parent = $node;
		return $this;
	}


	/**
	 * Returns the node's direct parent (direct ancestor).
	 *
	 *
	 * @return INode|NULL
	 */
	public function getParent()
	{
		return $this->parent;
	}


	/**
	 * Get a single child (direct descendant).
	 *
	 *
	 * @param scalar $index
	 * @return INode|FALSE returns FALSE when there is no child node under the index
	 */
	public function getChild($index)
	{
		if (!isset($this->children[$index])) {
			return FALSE;
		}
		return $this->children[$index];
	}


	/**
	 * Returns the index of the child within the children array.
	 *
	 *
	 * @param INode $node
	 * @return int|FALSE returns FALSE if the node is not a child of this node.
	 */
	public function getChildIndex(INode $node, $strict = TRUE)
	{
		return array_search($node, $this->children, $strict);
	}


	/**
	 * Returns all the node's children.
	 *
	 *
	 * @return INode[]
	 */
	public function getChildren()
	{
		return $this->children;
	}


	/**
	 * Appends a child into the children array.
	 * Consequently, this node becomes the direct parent of the inserted child node.
	 *
	 * Note: if an index is specified, it is possible to overwrite a previously added child node with the same index!
	 *
	 * Note: adding to NULL index is only possible by adding to '' (empty string) index.
	 *
	 *
	 * @param INode $node
	 * @param scalar $index = NULL index
	 * @return NodeBase the new/inserted node
	 */
	public function addChild(INode $node, $index = NULL)
	{
		$node->setParent($this);
		if ($index === NULL) {
			$this->children[] = $node;
		} else {
			$this->children[$index] = $node;
		}
		return $node;
	}


	/**
	 * Remove a child node by index or by an INode instance.
	 *
	 * Note:	this method does not tell whether the action was successful or not.
	 * 			To be sure, use methods getChild() or getChildIndex() before or after the call.
	 *
	 *
	 * @param int|string|INode $index an array index or an instance of INode
	 * @return NodeBase
	 */
	public function removeChild($index)
	{
		if ($index instanceof INode) {
			$index = $this->getChildIndex($index);
			if ($index === FALSE) {
				return $this;
			}
		}
		if (isset($this->children[$index])) {
			unset($this->children[$index]);
		}
		return $this;
	}


	/**
	 * Removes all the node's children.
	 *
	 *
	 * @return NodeBase fluent
	 */
	public function removeChildren()
	{
		$this->children = [];
		return $this;
	}

}
