<?php
// conexao.php
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'scorelogic';

try {
    // 1. Conectar sem ter base criada
    $pdo = new PDO("mysql:host=$host", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8mb4");

    // 2. Cria o banco de dados
    $dbCreate = "CREATE DATABASE IF NOT EXISTS $dbname 
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    $pdo->exec($dbCreate);

    // 2.1 Conectar à base criada
    $pdo->exec("USE $dbname");

    // Configurações globais (podem falhar sem privilégio; ignora erro)
    try {
        $pdo->exec("SET GLOBAL max_allowed_packet = 1073741824");
        $pdo->exec("SET GLOBAL net_buffer_length = 1000000");
    } catch (PDOException $e) { /* ignora */
    }

    // 3. Criando tabelas
    $tables = [
        "CREATE TABLE IF NOT EXISTS turmas(
            id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
            nome VARCHAR(100) NOT NULL,
            imagem MEDIUMBLOB NOT NULL,
            pontos INT NOT NULL DEFAULT 0,
            usuario VARCHAR(50) NOT NULL UNIQUE,
            senha VARCHAR(100) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS usuarios(
            id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
            usuario VARCHAR(50) NOT NULL UNIQUE,
            senha VARCHAR(100) NOT NULL,
            permissao ENUM('direcao', 'turma') NOT NULL DEFAULT 'turma',
            turma_id INT NULL,
            CONSTRAINT fk_usuario_turma FOREIGN KEY (turma_id) 
                REFERENCES turmas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS atividades(
            id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
            nome VARCHAR(100) NOT NULL,
            descricao TEXT,
            valor_padrao INT NOT NULL DEFAULT 20
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS pontuacoes(
            id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
            turma_id INT NOT NULL,
            atividade_id INT NOT NULL,
            pontos INT NOT NULL,
            data DATE NOT NULL,
            CONSTRAINT fk_pontuacao_turma FOREIGN KEY (turma_id) 
                REFERENCES turmas(id) ON DELETE CASCADE,
            CONSTRAINT fk_pontuacao_atividade FOREIGN KEY (atividade_id) 
                REFERENCES atividades(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS historico(
            id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
            acao VARCHAR(200) NOT NULL,
            detalhes TEXT,
            data DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS configuracoes(
            id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
            chave VARCHAR(50) NOT NULL UNIQUE,
            valor VARCHAR(50) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    ];

    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }

    // 4. Inserir dados iniciais
    // Só cria o admin padrão se NENHUM usuário com permissão 'direcao' existir
    $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE permissao = 'direcao'");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO usuarios (usuario, senha, permissao, turma_id) 
                    VALUES ('direcao', 'admin123', 'direcao', NULL)");
    }

    $pdo->exec("INSERT IGNORE INTO configuracoes (chave, valor) 
                VALUES ('bloquearRanking', 'false')");
} catch (PDOException $erro) {
    die("Erro ao conectar ao banco: " . $erro->getMessage());
}
