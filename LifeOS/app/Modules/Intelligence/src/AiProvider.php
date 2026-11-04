<?php
declare(strict_types=1);
interface AiProvider {
 public function models():array;
 public function submit(string $question,string $model,array $context=[]):string;
 public function result(string $id):array;
}
