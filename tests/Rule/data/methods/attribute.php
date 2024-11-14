<?php

namespace DeadAttribute;


#[\Attribute]
class Attr {

    #[self]
    public function __construct(string $name)
    {
    }
}

#[Attr("arg")]
#[Unknown("arg")]
class AttrUser
{

}
