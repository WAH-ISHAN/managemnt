<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$file=$argv[1] ?? ''; $mode=$argv[2] ?? '';
$root=realpath(__DIR__.'/.tmp'); $parent=realpath(dirname($file));
if (!$root || !$parent || !str_starts_with(strtolower($parent),strtolower($root.DIRECTORY_SEPARATOR))) throw new RuntimeException('Use only the disposable Windows test database.');
Db::init($file);
if ($mode==='seed') SalaryService::addIncome('2026-01-01','Installer preservation check','100000.01');
$entry=Db::one('SELECT * FROM income WHERE source=?',['Installer preservation check']);
if (!$entry || (int)round($entry['amount']*100)!==10000001) throw new RuntimeException('Saved income did not survive installation lifecycle.');
$sum=Db::one('SELECT SUM(amount) amount FROM allocations WHERE income_id=?',[$entry['id']]);
if ((int)round($sum['amount']*100)!==10000001) throw new RuntimeException('Saved allocation history did not survive installation lifecycle.');
Db::close(); echo "Saved income and allocation history verified.\n";
