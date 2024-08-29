<?php

namespace DeadAttribute;


#[\Attribute]
class Attr {

    public function __construct(string $name)
    {
    }
}

#[Attr("arg")]
#[Unknown("arg")]
class AttrUser
{

}
