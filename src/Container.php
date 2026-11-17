<?php
declare( strict_types=1 );

namespace WitsValley\Members;

use ReflectionClass;
use ReflectionNamedType;

/**
 * Minimal autowiring service container (singletons by class name).
 */
final class Container {

	/** @var array<class-string,object> */
	private array $instances = array();

	/** @var array<class-string,callable(self):object> */
	private array $factories = array();

	/**
	 * @template T of object
	 * @param class-string<T> $id
	 * @return T
	 */
	public function get( string $id ): object {
		if ( isset( $this->instances[ $id ] ) ) {
			/** @var T */
			return $this->instances[ $id ];
		}
		$object                 = isset( $this->factories[ $id ] ) ? ( $this->factories[ $id ] )( $this ) : $this->build( $id );
		$this->instances[ $id ] = $object;
		/** @var T $object */
		return $object;
	}

	/**
	 * @param class-string $id
	 * @param callable(self):object $factory
	 */
	public function factory( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/** @param class-string $id */
	public function set( string $id, object $instance ): void {
		$this->instances[ $id ] = $instance;
	}

	public function reset(): void {
		$this->instances = array();
	}

	/**
	 * @param class-string $id
	 */
	private function build( string $id ): object {
		$ref  = new ReflectionClass( $id );
		$ctor = $ref->getConstructor();
		if ( ! $ctor ) {
			return $ref->newInstance();
		}
		$args = array();
		foreach ( $ctor->getParameters() as $param ) {
			$type = $param->getType();
			if ( $type instanceof ReflectionNamedType && ! $type->isBuiltin() ) {
				/** @var class-string $name */
				$name   = $type->getName();
				$args[] = $this->get( $name );
			} elseif ( $param->isDefaultValueAvailable() ) {
				$args[] = $param->getDefaultValue();
			} else {
				throw new \LogicException( "Cannot autowire {$id}::\${$param->getName()}" );
			}
		}
		return $ref->newInstanceArgs( $args );
	}
}
