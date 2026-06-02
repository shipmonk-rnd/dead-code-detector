<?php

namespace NetteContainerProvider;

interface ProductFactory
{
    public function create(): FactoryProduct;
}

class FactoryProduct
{
    public function __construct() {}
}
