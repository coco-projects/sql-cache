<?php

    require '../vendor/autoload.php';

    use Coco\sqlCache\SqlParser;

    $sql = <<<"AAA"
SELECT DISTINCT 
  1+2 c1,
  1+ 2 AS `c2`,
  SUM(c2),
  SUM(c3) AS sum_c3,
  "Status" = 
  CASE
    WHEN quantity > 0 
    THEN 'in stock' 
    ELSE 'out of stock' 
  END case_statement,
  t4.c1,
  (SELECT 
    c1 + c2 
  FROM
    t1 inner_t1 
  LIMIT 1) AS subquery INTO @a1,
  @a2,
  @a3 
FROM
  t1 the_t1 
  LEFT OUTER JOIN t2 USING (c1, c2) 
  JOIN t3 AS tX 
    ON tX.c1 = the_t1.c1 
  JOIN t4 t4_x USING (X) 
WHERE c1 = 1 
  AND c2 IN (1, 2, 3, "apple") 
  AND EXISTS 
  (SELECT 
    1 
  FROM
    some_other_table another_table 
  WHERE X> 1) 
  AND ("zebra" = "orange" 
    OR 1 = 1) 
GROUP BY 1,
  2 
HAVING SUM(c2) > 1 
ORDER BY 2,
  c1 DESC 
LIMIT 0, 10 INTO OUTFILE "/xyz" FOR 
  UPDATE LOCK IN SHARE MODE 
AAA;
;

    $sql = SqlParser::getInstance($sql);

    print_r($sql->getSql());
    echo PHP_EOL;
    echo PHP_EOL;
    print_r($sql->getSqlHash());
    echo PHP_EOL;
    echo PHP_EOL;
    print_r($sql->getTables());