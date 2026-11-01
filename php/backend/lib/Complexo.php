<?php
/**
 * Aritmética de números complexos para o fluxo de carga.
 *
 * Representação: array(0 => real, 1 => imaginário).
 * Funções estáticas sobre arrays são mais rápidas no PHP 5.x do que objetos
 * imutáveis, o que importa quando a otimização roda dezenas de fluxos.
 */
class Complexo
{
    public static function criar($re, $im)
    {
        return array((float) $re, (float) $im);
    }

    public static function polar($modulo, $anguloRad)
    {
        return array($modulo * cos($anguloRad), $modulo * sin($anguloRad));
    }

    public static function soma($a, $b)
    {
        return array($a[0] + $b[0], $a[1] + $b[1]);
    }

    public static function sub($a, $b)
    {
        return array($a[0] - $b[0], $a[1] - $b[1]);
    }

    public static function mult($a, $b)
    {
        return array(
            $a[0] * $b[0] - $a[1] * $b[1],
            $a[0] * $b[1] + $a[1] * $b[0]
        );
    }

    /** Multiplica por escalar real */
    public static function escalar($a, $k)
    {
        return array($a[0] * $k, $a[1] * $k);
    }

    public static function div($a, $b)
    {
        $den = $b[0] * $b[0] + $b[1] * $b[1];
        if ($den == 0.0) {
            throw new RuntimeException('Divisão complexa por zero (tensão nula em algum nó).');
        }
        return array(
            ($a[0] * $b[0] + $a[1] * $b[1]) / $den,
            ($a[1] * $b[0] - $a[0] * $b[1]) / $den
        );
    }

    public static function conj($a)
    {
        return array($a[0], -$a[1]);
    }

    public static function modulo($a)
    {
        return sqrt($a[0] * $a[0] + $a[1] * $a[1]);
    }

    public static function angulo($a)
    {
        return atan2($a[1], $a[0]);
    }
}
