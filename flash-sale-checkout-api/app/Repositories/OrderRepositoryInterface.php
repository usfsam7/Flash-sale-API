<?php

namespace App\Repositories;
use App\Models\Order;

interface OrderRepositoryInterface
{
    public function store(array $data) : Order {};
}
