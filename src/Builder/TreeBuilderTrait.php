<?php


namespace Oliva\Utils\Tree\Builder;

use Traversable,
	RuntimeException;
use Oliva\Utils\Tree\Node\Node,
	Oliva\Utils\Tree\Node\INode;


/**
 * Tree builder base trait.
 *
 *
 * @author Andrej Rypak <xrypak@gmail.com>
 */
trait TreeBuilderTrait
{

	//
	//
	use CallbackTrait;
	//
	//


	/**
	 * Node class. An instance will be created upon transformation for each node.
	 * @var string
	 */
	public $nodeClass = NULL;

	/**
	 * @var array|NULL [callable, params]
	 */
	protected $nodeCallback = NULL;

	/**
	 * @var array|NULL [callable, params]
	 */
	protected $dataErrorCallback = NULL;


	/**
	 * Get the value of a member of the $item. The $item can be either array or object.
	 *
	 *
	 * @param mixed $item
	 * @param string|int $member
	 * @return mixed the value of the member
	 * @throws RuntimeException
	 */
	public function getMember($item, $member)
	{
		if (is_object($item)) {
			try {
				$value = $item->$member;
			} catch (Exception $e) {
				$value = $this->dataError($item, $member, new RuntimeException($this->formatMissingMemberMessage($item, $member), 1, $e));
			}
		} elseif (is_array($item) && key_exists($member, $item)) {
			$value = $item[$member];
		} else {
			$value = $this->dataError($item, $member, new RuntimeException($this->formatMissingMemberMessage($item, $member), 1));
		}
		return $value;
	}


	/**
	 * Get a member from the data, process the callback when applicable.
	 *
	 *
	 * @param mixed $item
	 * @param mixed $member member name or a callback; when using global namespace functions or global namespace object methods, prefix them with a backslash \ character
	 * @return mixed the value of the data member or the return value of the callback
	 * @throws RuntimeException
	 */
	public function getCallbackMember($item, $member)
	{
		if ($this->isAcceptableCallback($member)) {
			return call_user_func($member, $item, $this);
		} elseif (is_scalar($member)) {
			return $this->getMember($item, $member);
		}
		throw new RuntimeException('Incorrect getter provided. Please provide a name of a data member or a valid callback to retrieve it.');
	}


	/**
	 * Set the class name of newly created nodes.
	 * The constructor of the class used should take arbitrary data as the first argument to its constructor.
	 * 
	 * Note: if you set a node callback using setNodeCallback(), it has a precedence ovet the set class name.
	 * 
	 * 
	 * @param string $className
	 * @return self fluent
	 */
	public function setNodeClass($className)
	{
		$this->nodeClass = $className;
		return $this;
	}


	/**
	 * Register a callback for node creation.
	 * The first parameter of the callback is the data for the node.
	 * More parameters can be specified as arguments passed to this method's call.
	 *
	 * It is required, that the callback returns an instance of INode.
	 *
	  $builder->setNodeCallback(function($data = NULL) {
	  return new Node($data);
	  });
	 *
	  $builder->setNodeCallback(function($data, $customParam1, $customParam2) use($builder) {  ...  }, $customParam1_value, $customParam2_value);
	 *
	 *
	 * @param callable $function
	 * @return self fluent
	 */
	public function setNodeCallback(?callable $function = NULL/* , ...$params */)
	{
		if ($function !== NULL) {
			$this->nodeCallback = [$function, array_slice(func_get_args(), 1)]; // $params here [PHP 5.6]
		} else {
			$this->nodeCallback = NULL;
		}
		return $this;
	}


	/**
	 * Register a callback to handle data errors while building the tree.
	 * The first parameter of the callback is the data item for the node.
	 * The second parameter is the member that is being accessed.
	 * The third parameter is the exception causing the tree building error.
	 * More parameters can be specified as arguments passed to this method's call.
	 *
	  $builder->setDataErrorCallback(function($item, $member, $exception) { ... });
	 *
	  $builder->setDataErrorCallback(function($item, $member, $exception, ...$customParams) use($builder) {  ...  }, $customParam1_value, $customParam2_value);
	 *
	 *
	 * @param callable $function
	 * @return self fluent
	 */
	public function setDataErrorCallback(?callable $function = NULL/* , ...$params */)
	{
		if ($function !== NULL) {
			$this->dataErrorCallback = [$function, array_slice(func_get_args(), 1)]; // $params here [PHP 5.6]
		} else {
			$this->dataErrorCallback = NULL;
		}
		return $this;
	}


	/**
	 * Creates a node.
	 * If an argument is provided, it is passed to the constructor and callback (if provided).
	 *
	 *
	 * @return INode
	 */
	protected function createNode($data = NULL)
	{
		if ($this->nodeCallback !== NULL) {
			return call_user_func_array($this->nodeCallback[0], array_merge([$data], $this->nodeCallback[1]));
		}
		if ($this->nodeClass !== NULL) {
			return new $this->nodeClass($data);
		}
		// otherwise create a default node
		return new Node($data);
	}


	/**
	 * Handles data error.
	 * An exception can be thrown here or data can be repaired or whatever...
	 *
	 * Note: If the callback/method does not throw, the return value is used for node creation.
	 *
	 * Don't forget: user can specify his own error-handling routine using setDataErrorCallback() method!
	 *
	 *
	 * @return mixed
	 * @throws RuntimeException
	 */
	protected function dataError($item, $member, RuntimeException $exception)
	{
		if ($this->dataErrorCallback !== NULL) {
			return call_user_func_array($this->dataErrorCallback[0], array_merge([$item, $member, $exception], $this->dataErrorCallback[1]));
		}
		throw $exception;
	}


	/**
	 * Check the input data for correct type.
	 *
	 *
	 * @param Traversable|array $data
	 * @throws RuntimeException
	 */
	protected function checkData($data)
	{
		if (!is_array($data) && !$data instanceof Traversable) {
			throw new RuntimeException('The data provided must be an array or must be traversable, ' . (is_object($data) ? 'an instance of ' . get_class($data) : gettype($data) . '') . ' provided.', 2);
		}
	}


	/**
	 * Get name of the variable's type.
	 * 
	 * 
	 * @param mixed $item
	 * @return string
	 */
	protected function typeHint($item)
	{
		return is_object($item) ? get_class($item) : gettype($item);
	}


	protected function formatMissingMemberMessage($item, $member)
	{
		return sprintf('Missing %s "%s" of $item of type %s.', is_array($item) ? 'member' : 'attribute', $member, $this->typeHint($item));
	}

}
