# ⚡ BrProjetcEletric - Simulador de Fluxo Reverso e GD

Uma aplicação web interativa desenvolvida em Python para simulação de fluxo de carga em redes de distribuição de energia elétrica, com foco na análise de impactos da Geração Distribuída (GD) e otimização de comutadores de *tap*.

## 🎯 Objetivo do Projeto
Com a crescente inserção de sistemas fotovoltaicos (GD) nas redes de Baixa e Média Tensão, a análise de fluxo reverso e a regulação de tensão tornaram-se desafios diários na engenharia elétrica. 
Este projeto automatiza essa análise, validando os níveis de tensão (p.u.) e o carregamento dos condutores de acordo com os limites regulatórios do **PRODIST**.

## ✨ Funcionalidades
- **Cálculo em Tempo Real:** Simulação de fluxo de potência reativa e ativa (*Load Flow*) instantânea.
- **Detecção de Fluxo Reverso:** Alertas visuais quando a GD injeta mais potência do que o ramal consome, revertendo o fluxo no transformador.
- **Diagnóstico PRODIST:** Identificação automática de pontos de subtensão (< 0.93 p.u.) e sobretensão (> 1.05 p.u.).
- **Otimização de Tap:** Algoritmo que itera pelas posições do comutador do transformador (-2 a +2) para sugerir o melhor ajuste para o cenário de carga atual.

## 🛠️ Tecnologias Utilizadas
- **Backend (Motor Matemático):** Python, `pandapower`, `pandas`.
- **Backend (Servidor Web):** `Flask`.
- **Frontend (Interface e Gráficos):** HTML5, Tailwind CSS, JavaScript, Chart.js.

## 🚀 Como executar este projeto localmente

1. Clone este repositório:
```bash
git clone [https://github.com/SEU_USUARIO/SEU_REPOSITORIO.git](https://github.com/SEU_USUARIO/SEU_REPOSITORIO.git)
