<?php
// index.php - Sistema Score Logic com banco de dados MySQL
session_start();
require_once('conexao.php');

// ==================== FUNÇÕES AUXILIARES ====================
// Teste Git hub

function getTurmas($pdo)
{
    $stmt = $pdo->query("SELECT * FROM turmas ORDER BY pontos DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTurmaPorId($pdo, $id)
{
    $stmt = $pdo->prepare("SELECT * FROM turmas WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getAtividades($pdo)
{
    $stmt = $pdo->query("SELECT * FROM atividades");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getPontuacoes($pdo)
{
    $stmt = $pdo->query("SELECT p.*, t.nome AS turma_nome, a.nome AS atividade_nome 
                         FROM pontuacoes p 
                         JOIN turmas t ON p.turma_id = t.id 
                         JOIN atividades a ON p.atividade_id = a.id 
                         ORDER BY p.data DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getHistorico($pdo)
{
    $stmt = $pdo->query("SELECT * FROM historico ORDER BY data DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getConfiguracao($pdo, $chave)
{
    $stmt = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
    $stmt->execute([$chave]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['valor'] : 'false';
}

function getUsuarios($pdo)
{
    $stmt = $pdo->query("SELECT u.*, t.nome AS turma_nome 
                         FROM usuarios u 
                         LEFT JOIN turmas t ON u.turma_id = t.id");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function adicionarHistorico($pdo, $acao, $detalhes)
{
    $stmt = $pdo->prepare("INSERT INTO historico (acao, detalhes, data) VALUES (?, ?, NOW())");
    $stmt->execute([$acao, $detalhes]);
}

// ==================== SALVAR / ATUALIZAR ====================

function salvarTurma($pdo, $id, $nome, $imagem, $usuario, $senha)
{
    if ($id) {
        // Atualizar turma
        $stmt = $pdo->prepare("UPDATE turmas SET nome = ?, imagem = ?, usuario = ?, senha = ? WHERE id = ?");
        $stmt->execute([$nome, $imagem, $usuario, $senha, $id]);

        // Atualizar usuário vinculado (se existir)
        $stmt = $pdo->prepare("UPDATE usuarios SET usuario = ?, senha = ? WHERE turma_id = ?");
        $stmt->execute([$usuario, $senha, $id]);

        adicionarHistorico($pdo, "Edição", "Turma $nome e seu login atualizado");
        return $id;
    } else {
        // Inserir turma
        $stmt = $pdo->prepare("INSERT INTO turmas (nome, imagem, usuario, senha) VALUES (?, ?, ?, ?)");
        $stmt->execute([$nome, $imagem, $usuario, $senha]);
        $turmaId = $pdo->lastInsertId();

        // Criar usuário para a turma
        $stmt = $pdo->prepare("INSERT INTO usuarios (usuario, senha, permissao, turma_id) 
                               VALUES (?, ?, 'turma', ?)");
        $stmt->execute([$usuario, $senha, $turmaId]);

        adicionarHistorico($pdo, "Criação", "Turma $nome e login $usuario");
        return $turmaId;
    }
}

function excluirTurma($pdo, $id)
{
    $turma = getTurmaPorId($pdo, $id);
    $stmt = $pdo->prepare("DELETE FROM turmas WHERE id = ?");
    $stmt->execute([$id]);
    adicionarHistorico($pdo, "Exclusão", "Turma " . $turma['nome'] . " e seu login");
}

function salvarAtividade($pdo, $id, $nome, $descricao, $valor_padrao)
{
    if ($id) {
        $stmt = $pdo->prepare("UPDATE atividades SET nome = ?, descricao = ?, valor_padrao = ? WHERE id = ?");
        $stmt->execute([$nome, $descricao, $valor_padrao, $id]);
        adicionarHistorico($pdo, "Edição", "Atividade $nome (valor padrão: " . ($valor_padrao > 0 ? '+' : '') . $valor_padrao . ")");
    } else {
        $stmt = $pdo->prepare("INSERT INTO atividades (nome, descricao, valor_padrao) VALUES (?, ?, ?)");
        $stmt->execute([$nome, $descricao, $valor_padrao]);
        adicionarHistorico($pdo, "Criação", "Atividade $nome (valor padrão: " . ($valor_padrao > 0 ? '+' : '') . $valor_padrao . ")");
    }
}

function excluirAtividade($pdo, $id)
{
    $stmt = $pdo->prepare("SELECT nome FROM atividades WHERE id = ?");
    $stmt->execute([$id]);
    $atividade = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("DELETE FROM atividades WHERE id = ?");
    $stmt->execute([$id]);
    adicionarHistorico($pdo, "Exclusão", "Atividade " . $atividade['nome']);
}

function salvarPontuacao($pdo, $turma_id, $atividade_id, $pontos)
{
    $pdo->beginTransaction();
    try {
        // Inserir pontuação
        $stmt = $pdo->prepare("INSERT INTO pontuacoes (turma_id, atividade_id, pontos, data) 
                               VALUES (?, ?, ?, CURDATE())");
        $stmt->execute([$turma_id, $atividade_id, $pontos]);

        // Atualizar pontos da turma
        $stmt = $pdo->prepare("UPDATE turmas SET pontos = pontos + ? WHERE id = ?");
        $stmt->execute([$pontos, $turma_id]);

        // Registrar no histórico
        $stmt = $pdo->prepare("SELECT nome FROM turmas WHERE id = ?");
        $stmt->execute([$turma_id]);
        $turma = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT nome FROM atividades WHERE id = ?");
        $stmt->execute([$atividade_id]);
        $atividade = $stmt->fetch(PDO::FETCH_ASSOC);

        adicionarHistorico($pdo, "Pontuação", $turma['nome'] . ": " . ($pontos > 0 ? '+' : '') . $pontos . " em " . $atividade['nome']);

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function excluirPontuacao($pdo, $id)
{
    $stmt = $pdo->prepare("SELECT turma_id, pontos FROM pontuacoes WHERE id = ?");
    $stmt->execute([$id]);
    $pontuacao = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($pontuacao) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("DELETE FROM pontuacoes WHERE id = ?");
            $stmt->execute([$id]);

            $stmt = $pdo->prepare("UPDATE turmas SET pontos = pontos - ? WHERE id = ?");
            $stmt->execute([$pontuacao['pontos'], $pontuacao['turma_id']]);

            $pdo->commit();
            adicionarHistorico($pdo, "Exclusão", "Pontuação removida");
            return true;
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    return false;
}

function alternarBloqueioRanking($pdo)
{
    $atual = getConfiguracao($pdo, 'bloquearRanking');
    $novo = $atual === 'true' ? 'false' : 'true';
    $stmt = $pdo->prepare("UPDATE configuracoes SET valor = ? WHERE chave = 'bloquearRanking'");
    $stmt->execute([$novo]);
    adicionarHistorico($pdo, "Configuração", "Ranking " . ($novo === 'true' ? 'ocultou' : 'exibiu') . " pontuações das outras turmas");
}

function loginUsuario($pdo, $usuario, $senha)
{
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = ? AND senha = ?");
    $stmt->execute([$usuario, $senha]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $_SESSION['usuario'] = $user;
        return true;
    }
    return false;
}

// ==================== ADMINISTRADOR ====================
function alterarAdmin($pdo, $novoUsuario, $novaSenha)
{
    // Busca o admin atual (primeiro com permissão 'direcao')
    $stmt = $pdo->prepare("SELECT id, usuario FROM usuarios WHERE permissao = 'direcao' ORDER BY id LIMIT 1");
    $stmt->execute();
    $adminAtual = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$adminAtual) {
        throw new Exception("Nenhum usuário com permissão de direção encontrado.");
    }

    // Verifica se o novo nome já pertence a OUTRO usuário
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = ? AND id <> ?");
    $stmt->execute([$novoUsuario, $adminAtual['id']]);
    if ($stmt->fetch()) {
        throw new Exception("Já existe outro usuário com esse nome.");
    }

    // Atualiza credenciais do admin
    $stmt = $pdo->prepare("UPDATE usuarios SET usuario = ?, senha = ? WHERE id = ?");
    $stmt->execute([$novoUsuario, $novaSenha, $adminAtual['id']]);

    // Atualiza a sessão, se for o usuário logado
    if (isset($_SESSION['usuario']) && $_SESSION['usuario']['id'] == $adminAtual['id']) {
        $_SESSION['usuario']['usuario'] = $novoUsuario;
        $_SESSION['usuario']['senha']   = $novaSenha;
    }

    adicionarHistorico($pdo, "Configuração", "Credenciais do administrador atualizadas (novo usuário: $novoUsuario)");
}

// ==================== PROCESSAR REQUISIÇÕES AJAX ====================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => ''];

    try {
        switch ($action) {
            case 'login':
                if (loginUsuario($pdo, $_POST['usuario'], $_POST['senha'])) {
                    $response['success'] = true;
                    $response['user'] = $_SESSION['usuario'];
                } else {
                    $response['message'] = 'Usuário ou senha incorretos.';
                }
                break;

            case 'logout':
                session_destroy();
                $response['success'] = true;
                break;

            case 'saveClass':
                $id = $_POST['id'] ?: null;
                salvarTurma($pdo, $id, $_POST['nome'], $_POST['imagem'], $_POST['usuario'], $_POST['senha']);
                $response['success'] = true;
                break;

            case 'deleteClass':
                excluirTurma($pdo, $_POST['id']);
                $response['success'] = true;
                break;

            case 'saveActivity':
                salvarAtividade($pdo, $_POST['id'] ?: null, $_POST['nome'], $_POST['descricao'], $_POST['valor_padrao']);
                $response['success'] = true;
                break;

            case 'deleteActivity':
                excluirAtividade($pdo, $_POST['id']);
                $response['success'] = true;
                break;

            case 'saveScore':
                salvarPontuacao($pdo, $_POST['turma_id'], $_POST['atividade_id'], $_POST['pontos']);
                $response['success'] = true;
                break;

            case 'deleteScore':
                excluirPontuacao($pdo, $_POST['id']);
                $response['success'] = true;
                break;

            case 'toggleBlock':
                alternarBloqueioRanking($pdo);
                $response['success'] = true;
                break;

            case 'alterarAdmin':
                // Só a direção pode alterar
                if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['permissao'] !== 'direcao') {
                    throw new Exception("Apenas a direção pode alterar o administrador.");
                }
                alterarAdmin($pdo, $_POST['usuario'], $_POST['senha']);
                $response['success'] = true;
                break;
        }
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// ==================== DADOS PARA O FRONTEND ====================
$turmasData = getTurmas($pdo);
$atividadesData = getAtividades($pdo);
$pontuacoesData = getPontuacoes($pdo);
$historicoData = getHistorico($pdo);
$usuariosData = getUsuarios($pdo);
$bloquearRanking = getConfiguracao($pdo, 'bloquearRanking');
$sessaoUsuario = $_SESSION['usuario'] ?? null;
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="shortcut icon" href="logotipo.png" sizes="26x26">
    <title>Score Logic — Sistema Escolar</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: white;
            color: #173b2d;
            font-size: 14px;
        }

        header {
            background: #16824b;
            color: #fff;
            padding: 12px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        header h1 {
            font-size: 22px;
            margin: 0;
        }

        header small {
            font-size: 12px;
            opacity: 0.8;
        }

        .wrap {
            max-width: 1200px;
            margin: 16px auto;
            padding: 0 16px;
        }

        button {
            border: 0;
            border-radius: 6px;
            padding: 7px 12px;
            font-weight: bold;
            cursor: pointer;
            font-size: 13px;
            transition: opacity 0.2s;
        }

        button:hover {
            opacity: 0.8;
        }

        .green {
            background: #16824b;
            color: #fff;
        }

        .orange {
            background: #f28c28;
            color: #fff;
        }

        .gray {
            background: #e8eee9;
            color: #173b2d;
        }

        .danger {
            background: #d9534f;
            color: #fff;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 2px 10px rgba(23, 59, 45, 0.08);
            margin-bottom: 14px;
        }

        .card h2 {
            font-size: 18px;
            margin-bottom: 12px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 14px;
        }

        .stat {
            font-size: 24px;
            font-weight: bold;
        }

        .muted {
            color: #6b7b73;
            font-size: 12px;
        }

        .hidden {
            display: none !important;
        }

        nav {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        nav button {
            background: #e8eee9;
            color: #173b2d;
            padding: 6px 12px;
            font-size: 13px;
        }

        nav button.green {
            background: #16824b;
            color: #fff;
        }

        .classcard {
            padding: 12px;
            text-align: center;
        }

        .classcard img {
            width: 100%;
            height: 120px;
            object-fit: cover;
            border-radius: 10px;
            margin-bottom: 8px;
        }

        .classcard h3 {
            font-size: 15px;
            margin: 4px 0;
        }

        .classcard .points {
            font-size: 20px;
            font-weight: bold;
            margin: 2px 0;
        }

        .classcard .muted {
            font-size: 11px;
        }

        .classcard .actions {
            display: flex;
            gap: 6px;
            justify-content: center;
            margin-top: 8px;
            flex-wrap: wrap;
        }

        .classcard .actions button {
            font-size: 12px;
            padding: 5px 10px;
        }

        .rank {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-bottom: 1px solid #e5ebe7;
        }

        .rank:last-child {
            border-bottom: none;
        }

        .rank img {
            width: 44px;
            height: 44px;
            object-fit: cover;
            border-radius: 10px;
            flex-shrink: 0;
        }

        .pos {
            font-size: 18px;
            font-weight: bold;
            width: 32px;
            text-align: center;
            flex-shrink: 0;
        }

        .rank .points {
            font-size: 18px;
            font-weight: bold;
            margin-left: auto;
            flex-shrink: 0;
        }

        .positive {
            color: #16824b;
        }

        .negative {
            color: #c83b35;
        }

        .points-hidden {
            color: #999;
            font-size: 14px;
            font-weight: normal;
            letter-spacing: 1px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        th,
        td {
            text-align: left;
            padding: 8px 10px;
            border-bottom: 1px solid #e5ebe7;
        }

        th {
            background: #f4f7f5;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            color: #6b7b73;
            letter-spacing: 0.5px;
        }

        td .actions {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }

        td .actions button {
            font-size: 11px;
            padding: 4px 8px;
        }

        input,
        select {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #ccd8d1;
            border-radius: 6px;
            margin: 4px 0 10px;
            font-size: 13px;
        }

        label {
            font-weight: 600;
            font-size: 13px;
            display: block;
            margin-top: 4px;
        }

        .modal {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            z-index: 5;
        }

        .box {
            background: #fff;
            border-radius: 14px;
            padding: 22px;
            max-width: 480px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .box h2 {
            font-size: 18px;
            margin-bottom: 12px;
        }

        .login {
            max-width: 380px;
            margin: 60px auto;
            border: 8px solid #16824b;
        }

        .login-logo {
            text-align: center;
            font-size: 30px;
            font-weight: bold;
            color: #16824b;
            
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            background: #e7f5ec;
            color: #16824b;
            font-size: 11px;
            font-weight: bold;
        }

        .preview {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 12px;
            border: 2px solid #e8eee9;
            margin: 4px 0 10px;
        }

        .flex-row {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .flex-row input[type="number"] {
            width: 120px;
            margin: 0;
        }

        .locked-badge {
            background: #f28c28;
            color: #fff;
            padding: 2px 10px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: bold;
        }

        .text-center {
            text-align: center;
        }

        .mt-8 {
            margin-top: 8px;
        }

        .w-full {
            width: 100%;
        }

        .hint {
            font-size: 12px;
            color: #6b7b73;
            margin-top: 2px;
            display: block;
        }

        .my-class {
            background: #e8f5e9;
            border-radius: 10px;
            border: 2px solid #16824b;
        }

        .btn-block {
            background: #d9534f;
            color: #fff;
            padding: 8px 20px;
            font-size: 14px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: bold;
        }

        .btn-unblock {
            background: #16824b;
            color: #fff;
            padding: 8px 20px;
            font-size: 14px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: bold;
        }

        .config-row {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            padding: 8px 0;
        }

        .img {
            height: 175px;
            width: auto;
        }

        @media (max-width: 600px) {
            header {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }

            .grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            }

            .rank {
                flex-wrap: wrap;
                gap: 8px;
            }

            .rank .points {
                margin-left: 0;
                width: 100%;
                text-align: right;
            }

            table {
                font-size: 12px;
            }

            th,
            td {
                padding: 6px 6px;
            }

            .box {
                padding: 16px;
                margin: 10px;
            }
        }

        @media (max-width: 400px) {
            .grid {
                grid-template-columns: 1fr;
            }

            nav button {
                font-size: 11px;
                padding: 4px 8px;
            }
        }
    </style>
</head>

<body>
    <!-- Tela de Login -->
    <div id="loginScreen" <?php echo $sessaoUsuario ? 'class="hidden"' : ''; ?>>
        <div class="card login">
            <div class="login-logo"><img src="logotipo.png" class="img"></div>
            <label>Usuário</label>
            <input id="loginUsuario" placeholder="">
            <label>Senha</label>
            <input id="loginSenha" type="password" placeholder="">
            <button class="green w-full" onclick="login()">Entrar</button>
            <p id="loginError" class="negative text-center mt-8"></p>
        </div>
    </div>

    <!-- Sistema -->
    <div id="system" <?php echo $sessaoUsuario ? '' : 'class="hidden"'; ?>>
        <header>
            <div>
                <h1>Score Logic</h1>
                <small>Ranking escolar</small>
            </div>
            <div>
                <span id="who"><?php echo $sessaoUsuario ? htmlspecialchars($sessaoUsuario['usuario']) : ''; ?></span>
                <button class="gray" onclick="logout()">Sair</button>
            </div>
        </header>
        <div class="wrap">
            <div id="nav"></div>
            <div id="app"></div>
            <div class="login-logo"><img src="logotipo.png" class="img"></div>
        </div>
    </div>

    <div id="modal"></div>

    <script>
        // ==================== DADOS DO SERVIDOR ====================
        const serverData = {
            turmas: <?php echo json_encode($turmasData); ?>,
            atividades: <?php echo json_encode($atividadesData); ?>,
            pontuacoes: <?php echo json_encode($pontuacoesData); ?>,
            historico: <?php echo json_encode($historicoData); ?>,
            usuarios: <?php echo json_encode($usuariosData); ?>,
            configuracoes: {
                bloquearRanking: <?php echo json_encode($bloquearRanking === 'true'); ?>
            },
            sessao: <?php echo json_encode($sessaoUsuario); ?>
        };

        let sessao = serverData.sessao;
        let pagina = "dashboard";
        let db = {
            turmas: serverData.turmas,
            atividades: serverData.atividades,
            pontuacoes: serverData.pontuacoes,
            historico: serverData.historico,
            usuarios: serverData.usuarios,
            configuracoes: serverData.configuracoes
        };

        function esc(x) {
            return String(x ?? "").replace(/[&<>"']/g, m => ({
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
                "'": "&#039;"
            } [m]));
        }

        function turma(id) {
            return db.turmas.find(c => c.id == id);
        }

        function ordenadas() {
            return [...db.turmas].sort((a, b) => b.pontos - a.pontos);
        }

        // ==================== API ====================
        async function apiCall(action, data = {}) {
            const formData = new FormData();
            formData.append('action', action);
            Object.keys(data).forEach(key => formData.append(key, data[key]));

            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                return await response.json();
            } catch (error) {
                console.error('Erro na API:', error);
                return {
                    success: false,
                    message: error.message
                };
            }
        }

        async function login() {
            let u = loginUsuario.value.trim();
            let p = loginSenha.value;

            const result = await apiCall('login', {
                usuario: u,
                senha: p
            });

            if (result.success) {
                sessao = result.user;
                db.usuarios = [result.user, ...db.usuarios.filter(x => x.id !== result.user.id)];
                loginScreen.classList.add("hidden");
                system.classList.remove("hidden");
                document.getElementById('who').textContent = result.user.usuario;
                buildNav();
                show(result.user.permissao === "direcao" ? "dashboard" : "turmaHome");
            } else {
                loginError.textContent = result.message || "Usuário ou senha incorretos.";
            }
        }

        async function logout() {
            await apiCall('logout');
            sessao = null;
            system.classList.add("hidden");
            loginScreen.classList.remove("hidden");
            loginUsuario.value = "";
            loginSenha.value = "";
        }

        function buildNav() {
            if (sessao && sessao.permissao === "direcao") {
                nav.innerHTML = `
                    <nav>
                        <button class="green" onclick="show('dashboard')">Dashboard</button>
                        <button onclick="show('turmas')">🏫 Turmas</button>
                        <button onclick="show('atividades')">📝 Atividades</button>
                        <button onclick="show('pontuacoes')">⭐ Pontuações</button>
                        <button onclick="show('ranking')">🏆 Ranking</button>
                        <button onclick="show('logins')">🔐 Logins</button>
                        <button onclick="show('historico')">📜 Histórico</button>
                        <button onclick="show('config')">🔒 Ocultar pontuações</button>
                    </nav>`;
            } else if (sessao) {
                nav.innerHTML = `
                    <nav>
                        <button class="green" onclick="show('turmaHome')">🏫 Minha turma</button>
                        <button onclick="show('ranking')">🏆 Ranking</button>
                    </nav>`;
            }
        }

        function show(p) {
            pagina = p;
            let a = app;

            if (p === "dashboard") {
                const totalPontos = db.turmas.reduce((s, c) => s + Number(c.pontos), 0);
                const lider = ordenadas()[0];
                a.innerHTML = `
                    <div class="grid">
                        <div class="card stat">🏫 ${db.turmas.length}<div class="muted">Turmas</div></div>
                        <div class="card stat">⭐ ${totalPontos}<div class="muted">Pontos totais</div></div>
                        <div class="card stat">🔐 ${db.usuarios.length}<div class="muted">Logins</div></div>
                        <div class="card stat">🥇 ${lider ? esc(lider.nome) : "—"}<div class="muted">Líder</div></div>
                    </div>
                    <div class="card"><h2>🏆 Ranking</h2>${rank()}</div>`;
            }

            if (p === "turmas") {
                a.innerHTML = `
                    <div class="card">
                        <button class="green" onclick="turmaModal()">+ Nova turma</button>
                        <p class="muted mt-8">O brasão é selecionado diretamente dos arquivos do computador.</p>
                    </div>
                    <div class="grid">
                        ${db.turmas.map(c => `
                            <div class="card classcard">
                                <img src="${c.imagem || placeholder(c.nome)}" alt="${esc(c.nome)}">
                                <h3>${esc(c.nome)}</h3>
                                <div class="points ${c.pontos < 0 ? 'negative' : 'positive'}">${c.pontos} pts</div>
                                <div class="muted">Login: <b>${esc(c.usuario)}</b></div>
                                <div class="actions">
                                    <button class="gray" onclick="turmaModal(${c.id})">Editar</button>
                                    <button class="danger" onclick="delTurma(${c.id})">Excluir</button>
                                </div>
                            </div>`).join("")}
                    </div>`;
            }

            if (p === "atividades") {
                a.innerHTML = `
                    <div class="card">
                        <button class="orange" onclick="atividadeModal()">+ Nova atividade</button>
                    </div>
                    <div class="card">
                        <table>
                            <tr><th>Atividade</th><th>Descrição</th><th>Valor padrão</th><th style="text-align:right">Ações</th></tr>
                            ${db.atividades.map(x => `
                                <tr>
                                    <td><b>${esc(x.nome)}</b></td>
                                    <td>${esc(x.descricao)}</td>
                                    <td class="${x.valor_padrao < 0 ? 'negative' : 'positive'}">${x.valor_padrao > 0 ? '+' : ''}${x.valor_padrao}</td>
                                    <td style="text-align:right">
                                        <div class="actions" style="justify-content:flex-end">
                                            <button class="gray" onclick="atividadeModal(${x.id})">Editar</button>
                                            <button class="danger" onclick="delAtividade(${x.id})">Excluir</button>
                                        </div>
                                    </td>
                                </tr>`).join("")}
                        </table>
                    </div>`;
            }

            if (p === "pontuacoes") {
                a.innerHTML = `
                    <div class="card">
                        <button class="orange" onclick="pontuacaoModal()">+ Lançar pontuação</button>
                    </div>
                    <div class="card">
                        <table>
                            <tr><th>Turma</th><th>Atividade</th><th>Pontos</th><th>Data</th><th style="text-align:right">Ações</th></tr>
                            ${db.pontuacoes.slice().reverse().map(s => `
                                <tr>
                                    <td>${esc(turma(s.turma_id)?.nome)}</td>
                                    <td>${esc(db.atividades.find(x => x.id == s.atividade_id)?.nome)}</td>
                                    <td class="${s.pontos < 0 ? 'negative' : 'positive'}"><b>${s.pontos > 0 ? "+" : ""}${s.pontos}</b></td>
                                    <td>${s.data}</td>
                                    <td style="text-align:right">
                                        <button class="danger" onclick="removerPontuacao(${s.id})" style="font-size:11px;padding:3px 8px;">Excluir</button>
                                    </td>
                                </tr>`).join("")}
                        </table>
                    </div>`;
            }

            if (p === "ranking") {
                let bloqueado = db.configuracoes.bloquearRanking && sessao && sessao.permissao !== "direcao";
                let msg = bloqueado ?
                    `<div class="card" style="background:#fff3e0;border-left:4px solid #f28c28">
                        <p style="margin:0;color:#8d6e3f;font-size:13px;">🔒 <b>Pontuações ocultas</b> — Apenas a pontuação da sua turma está visível.</p>
                    </div>` : '';
                a.innerHTML = `<div class="card"><h2>🏆 Ranking das Turmas</h2>${rank(bloqueado)}</div>${msg}`;
            }

            if (p === "logins") {
                a.innerHTML = `
                    <div class="card">
                        <button class="green" onclick="usuarioModal()">+ Criar login de turma</button>
                        <p class="muted mt-8">A direção gerencia os usuários e senhas das turmas.</p>
                    </div>
                    <div class="card">
                        <table>
                            <tr><th>Turma</th><th>Usuário</th><th>Senha</th><th style="text-align:right">Ações</th></tr>
                            ${db.turmas.map(c => `
                                <tr>
                                    <td><b>${esc(c.nome)}</b></td>
                                    <td>${esc(c.usuario)}</td>
                                    <td>${esc(c.senha)}</td>
                                    <td style="text-align:right">
                                        <button class="gray" onclick="usuarioModal(${c.id})" style="font-size:12px;padding:4px 10px;">Alterar</button>
                                    </td>
                                </tr>`).join("")}
                        </table>
                    </div>
                    <div class="card">
                        <b>👑 Direção</b><br>
                        <span class="muted">Usuário: <b>${esc(sessao?.usuario || "direcao")}</b></span><br>
                        <span class="muted">Senha: ••••••••</span>
                        <div class="mt-8">
                            <button class="orange" onclick="adminModal()">🔑 Alterar usuário/senha</button>
                        </div>
                    </div>`;
            }

            if (p === "historico") {
                a.innerHTML = `
                    <div class="card">
                        <h2>📜 Histórico</h2>
                        <table>
                            <tr><th>Data</th><th>Ação</th><th>Detalhes</th></tr>
                            ${db.historico.slice().reverse().map(h => `
                                <tr>
                                    <td style="font-size:12px;white-space:nowrap;">${h.data}</td>
                                    <td>${esc(h.acao)}</td>
                                    <td>${esc(h.detalhes)}</td>
                                </tr>`).join("")}
                        </table>
                    </div>`;
            }

            if (p === "config") {
                let bloqueado = db.configuracoes.bloquearRanking;
                a.innerHTML = `
                    <div class="card">
                        <h2>🔒 Ocultar pontuações</h2>
                        <div class="config-row">
                            <span><b>Ocultar pontuações das outras turmas:</b></span>
                            ${bloqueado ?
                                `<button class="btn-unblock" onclick="toggleBloqueio()">🔓 Liberar pontuações</button>` :
                                `<button class="btn-block" onclick="toggleBloqueio()">🔒 Ocultar pontuações</button>`}
                            <span class="locked-badge" style="background:${bloqueado ? '#d9534f' : '#16824b'}">
                                ${bloqueado ? 'OCULTAS' : 'VISÍVEIS'}
                            </span>
                        </div>
                        <p class="muted">Quando ativado, as turmas veem a posição de todos no ranking, mas apenas os pontos da própria turma.</p>
                    </div>`;
            }

            if (p === "turmaHome") {
                let c = turma(sessao?.turma_id);
                if (!c) {
                    a.innerHTML = `<div class="card">Você não está vinculado a nenhuma turma.</div>`;
                    return;
                }
                let pos = ordenadas().findIndex(x => x.id == c.id) + 1;
                let bloqueado = db.configuracoes.bloquearRanking && sessao && sessao.permissao !== "direcao";

                a.innerHTML = `
                    <div class="card">
                        <div class="flex-row" style="gap:16px;">
                            <img class="preview" src="${c.imagem || placeholder(c.nome)}" alt="${esc(c.nome)}">
                            <div>
                                <h2 style="font-size:20px;margin:0;">${esc(c.nome)}</h2>
                                <span class="badge">Login da turma</span>
                                <div class="points ${c.pontos < 0 ? 'negative' : 'positive'}" style="font-size:28px;">${c.pontos} pontos</div>
                                <div class="muted">🏆 Posição: ${pos}º lugar</div>
                            </div>
                        </div>
                        ${bloqueado ?
                            `<p class="muted mt-8" style="color:#f28c28;">🔒 As pontuações das outras turmas estão ocultas.</p>` :
                            `<p class="muted mt-8">Você pode consultar sua pontuação e o ranking. Somente a direção pode alterar pontos.</p>`}
                    </div>
                    <div class="card">
                        <h2>🏆 Posição no ranking</h2>
                        ${rank(bloqueado)}
                    </div>`;
            }
        }

        function placeholder(nome) {
            return "data:image/svg+xml;charset=UTF-8," + encodeURIComponent(
                `<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300"><rect width="100%" height="100%" fill="#16824b"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="white" font-size="42" font-family="Arial">${nome}</text></svg>`
            );
        }

        function rank(ocultarPontos) {
            let lista = ordenadas();

            if (ocultarPontos && sessao && sessao.permissao !== "direcao") {
                return lista.map((c, i) => {
                    let minha = c.id == sessao.turma_id;
                    let pontosDisplay = minha ?
                        `<strong class="${c.pontos < 0 ? 'negative' : 'positive'}">${c.pontos} pts</strong>` :
                        `<span class="points-hidden">••• pts</span>`;
                    return `
                        <div class="rank ${minha ? 'my-class' : ''}">
                            <div class="pos">${i + 1}º</div>
                            <img src="${c.imagem || placeholder(c.nome)}" alt="${esc(c.nome)}">
                            <div style="flex:1;min-width:60px;"><b>${esc(c.nome)}</b> ${minha ? '⭐' : ''}<br><span class="badge">Turma</span></div>
                            ${pontosDisplay}
                        </div>`;
                }).join("");
            }

            return lista.map((c, i) => `
                <div class="rank">
                    <div class="pos">${i + 1}º</div>
                    <img src="${c.imagem || placeholder(c.nome)}" alt="${esc(c.nome)}">
                    <div style="flex:1;min-width:60px;"><b>${esc(c.nome)}</b><br><span class="badge">Turma</span></div>
                    <strong class="${c.pontos < 0 ? 'negative' : 'positive'}">${c.pontos} pts</strong>
                </div>`).join("");
        }

        function openM(x) {
            modal.innerHTML = `<div class="modal" onclick="if(event.target===this)closeM()"><div class="box">${x}<br><button class="gray" onclick="closeM()" style="margin-top:10px;">Cancelar</button></div></div>`;
        }

        function closeM() {
            modal.innerHTML = "";
        }

        // ==================== TURMAS ====================
        function turmaModal(id) {
            let c = id ? turma(id) : {
                nome: "",
                imagem: "",
                usuario: "",
                senha: ""
            };
            openM(`
                <h2>${id ? "Editar" : "Cadastrar"} turma</h2>
                <label>Nome</label>
                <input id="cn" value="${esc(c.nome)}" placeholder="Ex.: 3°B">
                <label>Brasão / imagem</label>
                <input id="file" type="file" accept="image/*" onchange="lerImagem(event)">
                <input id="imagemData" type="hidden" value="${esc(c.imagem || "")}">
                <img id="prev" class="preview" src="${c.imagem || placeholder(c.nome || "Turma")}" alt="Preview">
                <label>Usuário da turma</label>
                <input id="cu" value="${esc(c.usuario)}" placeholder="Ex.: 3B">
                <label>Senha da turma</label>
                <input id="cp" value="${esc(c.senha)}" placeholder="Senha">
                <button class="green w-full" onclick="salvarTurma(${id || 0})">Salvar turma</button>`);
        }

        function lerImagem(e) {
            let f = e.target.files[0];
            if (!f) return;
            if (!f.type.startsWith("image/")) return alert("Selecione uma imagem.");
            if (f.size > 4 * 1024 * 1024) return alert("Imagem de até 4 MB.");
            let r = new FileReader();
            r.onload = () => {
                imagemData.value = r.result;
                prev.src = r.result;
            };
            r.readAsDataURL(f);
        }

        async function salvarTurma(id) {
            let n = cn.value.trim();
            let im = imagemData.value;
            let u = cu.value.trim();
            let p = cp.value;
            if (!n || !im || !u || !p) return alert("Preencha nome, brasão, usuário e senha.");

            const result = await apiCall('saveClass', {
                id,
                nome: n,
                imagem: im,
                usuario: u,
                senha: p
            });
            if (result.success) location.reload();
            else alert('Erro ao salvar: ' + result.message);
        }

        async function delTurma(id) {
            let c = turma(id);
            if (!confirm("Excluir " + c.nome + " e seu login?")) return;
            const result = await apiCall('deleteClass', {
                id
            });
            if (result.success) location.reload();
            else alert('Erro ao excluir: ' + result.message);
        }

        // ==================== USUÁRIOS ====================
        function usuarioModal(id) {
            let c = turma(id);
            openM(`
                <h2>${id ? "Alterar login" : "Criar login de turma"}</h2>
                <label>Turma</label>
                <select id="uc">
                    ${db.turmas.map(x => `<option value="${x.id}" ${id == x.id ? "selected" : ""}>${esc(x.nome)}</option>`).join("")}
                </select>
                <label>Usuário</label>
                <input id="uu" value="${esc(c?.usuario || "")}" placeholder="Usuário">
                <label>Senha</label>
                <input id="up" value="${esc(c?.senha || "")}" placeholder="Senha">
                <button class="green w-full" onclick="salvarUsuario(${id || 0})">Salvar acesso</button>`);
        }

        async function salvarUsuario(id) {
            let c = turma(uc.value);
            let u = uu.value.trim();
            let p = up.value;
            if (!c || !u || !p) return alert("Preencha os dados.");
            const result = await apiCall('saveClass', {
                id: c.id,
                nome: c.nome,
                imagem: c.imagem,
                usuario: u,
                senha: p
            });
            if (result.success) location.reload();
            else alert('Erro ao salvar: ' + result.message);
        }

        // ==================== ADMINISTRADOR ====================
        function adminModal() {
            openM(`
                <h2>🔑 Alterar credenciais da direção</h2>
                <label>Novo usuário</label>
                <input id="au" value="${esc(sessao?.usuario || "direcao")}" placeholder="Usuário">
                <label>Nova senha</label>
                <input id="ap" type="password" value="" placeholder="Nova senha">
                <label>Confirmar nova senha</label>
                <input id="ap2" type="password" value="" placeholder="Repita a nova senha">
                <span class="hint">💡 Você precisará usar essas credenciais no próximo login.</span>
                <button class="orange w-full" onclick="salvarAdmin()">Salvar credenciais</button>`);
        }

        async function salvarAdmin() {
            let u = document.getElementById('au').value.trim();
            let p = document.getElementById('ap').value;
            let p2 = document.getElementById('ap2').value;

            if (!u || !p) return alert("Preencha usuário e senha.");
            if (p !== p2) return alert("As senhas não coincidem.");
            if (p.length < 4) return alert("A senha deve ter pelo menos 4 caracteres.");

            const result = await apiCall('alterarAdmin', {
                usuario: u,
                senha: p
            });

            if (result.success) {
                alert("Credenciais atualizadas com sucesso!");
                location.reload();
            } else {
                alert('Erro ao alterar: ' + result.message);
            }
        }

        // ==================== ATIVIDADES ====================
        function atividadeModal(id) {
            let x = id ? db.atividades.find(a => a.id == id) : {
                nome: "",
                descricao: "",
                valor_padrao: 20
            };
            openM(`
                <h2>${id ? "Editar" : "Nova"} atividade</h2>
                <label>Nome</label>
                <input id="an" value="${esc(x.nome)}" placeholder="Ex.: Prova 1">
                <label>Descrição</label>
                <input id="ad" value="${esc(x.descricao)}" placeholder="Descrição da atividade">
                <label>Valor padrão</label>
                <div class="flex-row">
                    <input id="adef" type="number" value="${x.valor_padrao || 20}" step="1">
                    <span class="muted">Use números positivos ou negativos</span>
                </div>
                <button class="orange w-full" onclick="salvarAtividade(${id || 0})">Salvar</button>`);
        }

        async function salvarAtividade(id) {
            let n = an.value.trim();
            let d = ad.value.trim();
            let def = Number(adef.value);
            if (!n) return alert("Preencha o nome da atividade.");
            if (!Number.isFinite(def) || def === 0) return alert("O valor padrão não pode ser zero.");
            const result = await apiCall('saveActivity', {
                id,
                nome: n,
                descricao: d,
                valor_padrao: def
            });
            if (result.success) location.reload();
            else alert('Erro ao salvar: ' + result.message);
        }

        async function delAtividade(id) {
            let x = db.atividades.find(a => a.id == id);
            if (!confirm("Excluir " + x.nome + "?")) return;
            const result = await apiCall('deleteActivity', {
                id
            });
            if (result.success) location.reload();
            else alert('Erro ao excluir: ' + result.message);
        }

        // ==================== PONTUAÇÕES ====================
        function pontuacaoModal() {
            if (db.atividades.length === 0) return alert("Cadastre uma atividade primeiro!");
            if (db.turmas.length === 0) return alert("Cadastre uma turma primeiro!");
            openM(`
                <h2>Lançar pontuação</h2>
                <label>Turma</label>
                <select id="sc">${db.turmas.map(c => `<option value="${c.id}">${esc(c.nome)} — ${c.pontos} pts</option>`).join("")}</select>
                <label>Atividade</label>
                <select id="sa" onchange="atualizarValorPadrao()">
                    ${db.atividades.map(a => `<option value="${a.id}" data-padrao="${a.valor_padrao || 20}">${esc(a.nome)} — padrão: ${a.valor_padrao > 0 ? '+' : ''}${a.valor_padrao}</option>`).join("")}
                </select>
                <label>Pontos (positivos ou negativos)</label>
                <div class="flex-row">
                    <input id="sp" type="number" value="${db.atividades[0].valor_padrao || 20}" step="1">
                    <span class="muted">Valor padrão da atividade</span>
                </div>
                <button class="orange w-full" onclick="salvarPontuacao()">Registrar</button>`);
            atualizarValorPadrao();
        }

        function atualizarValorPadrao() {
            let sel = document.getElementById('sa');
            let input = document.getElementById('sp');
            if (sel && input) {
                let opt = sel.options[sel.selectedIndex];
                let def = opt ? parseFloat(opt.dataset.padrao) || 20 : 20;
                input.value = def;
            }
        }

        async function salvarPontuacao() {
            let p = parseFloat(sp.value);
            let c = turma(sc.value);
            let a = db.atividades.find(x => x.id == sa.value);
            if (!Number.isFinite(p) || p === 0) return alert("Informe um valor diferente de zero.");
            if (p > 0 && p > Math.abs(a.valor_padrao))
                return alert("O valor positivo (" + p + ") ultrapassa o máximo da atividade (" + Math.abs(a.valor_padrao) + ").");
            const result = await apiCall('saveScore', {
                turma_id: c.id,
                atividade_id: a.id,
                pontos: p
            });
            if (result.success) location.reload();
            else alert('Erro ao salvar: ' + result.message);
        }

        async function removerPontuacao(id) {
            if (!confirm("Excluir lançamento?")) return;
            const result = await apiCall('deleteScore', {
                id
            });
            if (result.success) location.reload();
            else alert('Erro ao excluir: ' + result.message);
        }

        async function toggleBloqueio() {
            const result = await apiCall('toggleBlock');
            if (result.success) location.reload();
            else alert('Erro ao alterar: ' + result.message);
        }

        // ==================== INICIALIZAÇÃO ====================
        if (sessao) {
            buildNav();
            show(sessao.permissao === "direcao" ? "dashboard" : "turmaHome");
        }
    </script>
</body>

</html>