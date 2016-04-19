<?php
namespace CodeIT\Filter;

use Zend\Filter\UriNormalize;

class RedirectUrlFilter extends UriNormalize {

	/**
	 * Sets filter options and url scheme
	 *
	 * @param string $scheme
	 * @param array|\Traversable|null $options
	 */
	public function __construct($scheme, $options = null) {
		$this->setEnforcedScheme($scheme);
		parent::__construct($options);
	}

    /**
     * Filter the URL by normalizing it and applying a default scheme if set. Set to '' if no consistance with URL const.
     *
     * @param  string $value
     * @return string
     */
	public function filter($value) {
        $uriFiltered = parent::filter($value);

		if (strpos($uriFiltered, URL) !== 0) {
			$uriFiltered = '';
		}

        return $uriFiltered;
    }

}
