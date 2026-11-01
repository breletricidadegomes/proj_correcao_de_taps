<?php
/**
 * Curvas típicas diárias (dia útil), normalizadas pelo valor de pico = 1,0.
 *
 * Valores horários de referência para estudos; substitua pelas curvas da sua
 * distribuidora (BDGD: entidade CRVCRG, 96 pontos de 15 min) quando disponíveis.
 * Os pontos de 15 min são obtidos por interpolação linear entre as horas.
 */
class CurvasTipicas
{
    /** Perfis de carga horários (00h a 23h) */
    public static function perfisCarga()
    {
        $res = array(0.45, 0.40, 0.37, 0.36, 0.37, 0.42, 0.52, 0.58, 0.55, 0.52, 0.52, 0.55,
                     0.58, 0.55, 0.52, 0.52, 0.56, 0.68, 0.90, 1.00, 0.97, 0.88, 0.72, 0.56);
        $com = array(0.30, 0.28, 0.27, 0.27, 0.28, 0.32, 0.42, 0.60, 0.82, 0.92, 0.97, 1.00,
                     0.95, 0.97, 1.00, 0.98, 0.92, 0.82, 0.65, 0.52, 0.45, 0.40, 0.35, 0.32);
        $ind = array(0.70, 0.68, 0.67, 0.67, 0.68, 0.72, 0.82, 0.92, 0.98, 1.00, 1.00, 0.98,
                     0.90, 0.97, 1.00, 0.99, 0.96, 0.88, 0.80, 0.78, 0.76, 0.74, 0.72, 0.71);

        // Misto: 50% residencial, 30% comercial, 20% industrial, renormalizado ao pico
        $mis = array();
        for ($h = 0; $h < 24; $h++) {
            $mis[$h] = 0.5 * $res[$h] + 0.3 * $com[$h] + 0.2 * $ind[$h];
        }
        $pico = max($mis);
        foreach ($mis as $h => $v) {
            $mis[$h] = $v / $pico;
        }

        return array(
            'misto'       => array('nome' => 'Misto (50% res. / 30% com. / 20% ind.)', 'horas' => $mis),
            'residencial' => array('nome' => 'Residencial (ponta às 19h)', 'horas' => $res),
            'comercial'   => array('nome' => 'Comercial (pico em horário comercial)', 'horas' => $com),
            'industrial'  => array('nome' => 'Industrial (curva achatada)', 'horas' => $ind),
        );
    }

    public static function perfisCeu()
    {
        return array(
            'ensolarado' => 'Céu limpo',
            'parcial'    => 'Parcialmente nublado (nuvens passageiras)',
            'nublado'    => 'Encoberto',
        );
    }

    /** Fator de carga (0–1) no instante t (horas decimais, 0 ≤ t < 24) */
    public static function carga($perfil, $t)
    {
        $perfis = self::perfisCarga();
        $c = $perfis[$perfil]['horas'];
        $h0 = (int) floor($t) % 24;
        $h1 = ($h0 + 1) % 24;
        $f = $t - floor($t);
        return $c[$h0] + ($c[$h1] - $c[$h0]) * $f;
    }

    /**
     * Fator de geração solar (0–1) no instante t.
     * Céu limpo: meia senoide entre 6h e 18h.
     * Parcial: céu limpo modulado por nuvens determinísticas (mesmo resultado
     * em qualquer servidor — sem mt_rand, cujo algoritmo mudou no PHP 7.1).
     */
    public static function solar($perfil, $t)
    {
        if ($t <= 6.0 || $t >= 18.0) {
            return 0.0;
        }
        $limpo = pow(sin(M_PI * ($t - 6.0) / 12.0), 1.2);
        if ($perfil === 'nublado') {
            return 0.30 * $limpo;
        }
        if ($perfil === 'parcial') {
            $nuvem = 0.5 + 0.5 * sin(5.3 * $t) * cos(1.9 * $t + 0.7);   // 0..1
            $atenuacao = $nuvem > 0.55 ? 0.35 : 1.0;                     // nuvem passa / sai
            return $limpo * $atenuacao;
        }
        return $limpo;
    }
}
