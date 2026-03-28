from flask import Flask, render_template, request, jsonify
import pandapower as pp
import pandas as pd

app = Flask(__name__)

# Limites do PRODIST para Baixa Tensão
V_MIN_PU = 0.93
V_MAX_PU = 1.05

def create_network(params, tap_pos=0):
    """Cria a topologia da rede baseada nos parâmetros do frontend."""
    net = pp.create_empty_network()

    # 1. Barramentos (Nós)
    # IDs no pandapower serão criados na ordem: 0, 1, 2, 3, 4
    b0 = pp.create_bus(net, vn_kv=13.8, name="Barra SE (MT)")
    b1 = pp.create_bus(net, vn_kv=0.38, name="Saída Trafo (BT)")
    b2 = pp.create_bus(net, vn_kv=0.38, name="Poste A (BT)")
    b3 = pp.create_bus(net, vn_kv=0.38, name="Poste GD (BT)")
    b4 = pp.create_bus(net, vn_kv=0.38, name="Poste C (BT)")

    # 2. Rede Externa (Subestação / Alimentador)
    vm_pu_mt = params.get('vm_pu', 1.0)
    pp.create_ext_grid(net, bus=b0, vm_pu=vm_pu_mt)

# 3. Transformador (Criado com parâmetros elétricos reais para 13.8kV)
    pp.create_transformer_from_parameters(
        net, 
        hv_bus=b0, 
        lv_bus=b1, 
        sn_mva=0.1,        # Potência do trafo: 100 kVA
        vn_hv_kv=13.8,     # Tensão no primário
        vn_lv_kv=0.38,     # Tensão no secundário
        vkr_percent=1.0,   # Resistência de curto-circuito (típico)
        vk_percent=4.0,    # Impedância de curto-circuito (Z% na placa)
        pfe_kw=0.3,        # Perdas no ferro
        i0_percent=1.0,    # Corrente de excitação
        name="Trafo 100kVA"
    )
    
    # Parâmetros do comutador de Tap
    net.trafo.loc[0, 'tap_side'] = 'hv'
    net.trafo.loc[0, 'tap_step_percent'] = 2.5
    net.trafo.loc[0, 'tap_min'] = -2
    net.trafo.loc[0, 'tap_max'] = 2
    net.trafo.loc[0, 'tap_neutral'] = 0
    net.trafo.loc[0, 'tap_pos'] = tap_pos
    
    # 4. Linhas (Traços)
    pp.create_line(net, from_bus=b1, to_bus=b2, length_km=0.05, std_type="NAYY 4x50 SE")
    pp.create_line(net, from_bus=b2, to_bus=b3, length_km=0.05, std_type="NAYY 4x50 SE")
    pp.create_line(net, from_bus=b3, to_bus=b4, length_km=0.05, std_type="NAYY 4x50 SE")

    # 5. Cargas (Convertendo kW do frontend para MW do pandapower)
    pp.create_load(net, bus=b2, p_mw=params.get('load_a_kw', 10) / 1000, q_mvar=0)
    pp.create_load(net, bus=b3, p_mw=params.get('load_b_kw', 10) / 1000, q_mvar=0)
    pp.create_load(net, bus=b4, p_mw=params.get('load_c_kw', 10) / 1000, q_mvar=0)

    # 6. Geração Distribuída (Convertendo kW para MW)
    pp.create_sgen(net, bus=b3, p_mw=params.get('gd_kw', 0) / 1000, q_mvar=0)

    return net

def extract_results(net, params):
    """Extrai os resultados do pandapower e formata para o frontend."""
    # Barramentos
    buses = []
    for i in net.bus.index:
        vm = round(net.res_bus.at[i, 'vm_pu'], 3)
        status = 'normal'
        if vm > V_MAX_PU: status = 'sobretensão'
        elif vm < V_MIN_PU: status = 'subtensão'
        
        buses.append({
            'id': int(i),
            'name': net.bus.at[i, 'name'],
            'vm_pu': vm,
            'va_degree': round(net.res_bus.at[i, 'va_degree'], 1),
            'status': status
        })

    # Linhas
    lines = []
    for i in net.line.index:
        p_from_kw = round(net.res_line.at[i, 'p_from_mw'] * 1000, 1)
        p_to_kw = round(net.res_line.at[i, 'p_to_mw'] * 1000, 1)
        # Se p_from é negativo, a energia está voltando (fluxo reverso)
        reverse_flow = p_from_kw < 0
        
        lines.append({
            'id': int(i),
            'from_bus': int(net.line.at[i, 'from_bus']),
            'to_bus': int(net.line.at[i, 'to_bus']),
            'p_from_kw': p_from_kw,
            'p_to_kw': p_to_kw,
            'loading_percent': round(net.res_line.at[i, 'loading_percent'], 1),
            'reverse_flow': bool(reverse_flow)
        })

    # Transformadores
    trafos = []
    has_reverse_flow_global = False
    for i in net.trafo.index:
        p_hv_kw = round(net.res_trafo.at[i, 'p_hv_mw'] * 1000, 1)
        p_lv_kw = round(net.res_trafo.at[i, 'p_lv_mw'] * 1000, 1)
        # Se p_hv é negativo, a energia da BT está subindo para a MT
        reverse_flow = p_hv_kw < 0
        if reverse_flow: has_reverse_flow_global = True
        
        trafos.append({
            'p_hv_kw': p_hv_kw,
            'p_lv_kw': p_lv_kw,
            'loading_percent': round(net.res_trafo.at[i, 'loading_percent'], 1),
            'tap_pos': int(net.trafo.at[i, 'tap_pos']),
            'reverse_flow': bool(reverse_flow)
        })

    # Verifica se há fluxo reverso nas linhas também
    if any(l['reverse_flow'] for l in lines):
        has_reverse_flow_global = True

    total_load_kw = params.get('load_a_kw', 0) + params.get('load_b_kw', 0) + params.get('load_c_kw', 0)
    total_generation_kw = params.get('gd_kw', 0)

    return {
        'buses': buses,
        'lines': lines,
        'trafos': trafos,
        'total_load_kw': total_load_kw,
        'total_generation_kw': total_generation_kw,
        'net_injection_kw': total_generation_kw - total_load_kw,
        'has_reverse_flow': has_reverse_flow_global
    }


# ================= ROTAS DO FLASK ================= #

@app.route('/')
def index():
    """Renderiza o HTML principal."""
    # Assumindo que seu HTML se chama index.html e está na pasta 'templates'
    return render_template('index.html') 

@app.route('/api/simulate', methods=['POST'])
def simulate():
    """Endpoint principal de simulação."""
    params = request.json
    
    try:
        # Pega a posição de tap sugerida ou usa 0 como padrão
        tap_pos = params.get('tap_pos', 0)
        net = create_network(params, tap_pos)
        pp.runpp(net)
        results = extract_results(net, params)
        return jsonify(results)
    except pp.pandapower.LoadflowNotConverged:
        return jsonify({'error': 'Fluxo de carga não convergiu. Verifique se os parâmetros de carga/geração não são absurdamente altos.'}), 400
    except Exception as e:
        return jsonify({'error': str(e)}), 500

@app.route('/api/optimize_tap', methods=['POST'])
def optimize_tap():
    """Endpoint para encontrar a melhor posição de tap no transformador."""
    params = request.json
    results_list = []
    best_tap = None
    min_deviation = float('inf')

    try:
        for tap in range(-2, 3): # Testa posições de -2 a +2
            net = create_network(params, tap)
            try:
                pp.runpp(net)
                
                # Avalia apenas os barramentos de Baixa Tensão (BT)
                barras_bt = net.bus[net.bus.vn_kv < 1.0].index
                v_min = round(net.res_bus.loc[barras_bt, 'vm_pu'].min(), 3)
                v_max = round(net.res_bus.loc[barras_bt, 'vm_pu'].max(), 3)
                
                status = 'OK'
                if v_min < V_MIN_PU or v_max > V_MAX_PU:
                    status = 'VIOLAÇÃO'
                
                # Calcula o quão longe as tensões estão do ideal (1.0 pu) para critério de desempate
                deviation = round(abs(1.0 - v_min) + abs(1.0 - v_max), 3)

                results_list.append({
                    'tap': tap,
                    'v_min': v_min,
                    'v_max': v_max,
                    'deviation': deviation,
                    'status': status
                })

                # Salva o melhor tap que não causa violação e tem menor desvio
                if status == 'OK' and deviation < min_deviation:
                    min_deviation = deviation
                    best_tap = tap

            except pp.pandapower.LoadflowNotConverged:
                results_list.append({
                    'tap': tap,
                    'status': 'NÃO CONVERGIU'
                })

        return jsonify({
            'best_tap': best_tap,
            'results': results_list
        })

    except Exception as e:
        return jsonify({'error': str(e)}), 500

if __name__ == '__main__':
    # Roda o servidor na porta padrão do Replit (0.0.0.0 garante acesso externo)
    app.run(host='0.0.0.0', port=8080, debug=True)
