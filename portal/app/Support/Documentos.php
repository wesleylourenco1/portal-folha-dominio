<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use ZipArchive;

/**
 * Normaliza os arquivos enviados numa admissão: documentos soltos passam direto e arquivos .zip
 * são abertos em memória, aproveitando só os documentos suportados (inclusive de subpastas).
 *
 * Proteções contra zip malicioso: limite de arquivos, de tamanho por arquivo e total (zip bomb),
 * nada é gravado com o caminho de dentro do zip (zip slip), pastas de sistema são ignoradas.
 */
class Documentos
{
    public const EXTENSOES = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png', 'webp' => 'image/webp'];

    public const MAX_ARQUIVOS = 40;

    public const MAX_POR_ARQUIVO = 20 * 1024 * 1024;     // 20 MB

    public const MAX_TOTAL = 150 * 1024 * 1024;          // 150 MB descompactados

    /**
     * @param  UploadedFile[]  $enviados
     * @return array<int, array{nome: string, conteudo: string, mime: string}>
     */
    public static function expandir(array $enviados): array
    {
        $saida = [];
        $total = 0;
        foreach ($enviados as $arquivo) {
            $ext = strtolower($arquivo->getClientOriginalExtension());
            if ($ext === 'zip') {
                foreach (self::abrirZip($arquivo->getRealPath(), $arquivo->getClientOriginalName()) as $doc) {
                    $total += strlen($doc['conteudo']);
                    $saida[] = $doc;
                }
            } elseif (isset(self::EXTENSOES[$ext])) {
                $conteudo = file_get_contents($arquivo->getRealPath());
                $total += strlen($conteudo);
                $saida[] = ['nome' => self::nomeSeguro($arquivo->getClientOriginalName()), 'conteudo' => $conteudo, 'mime' => self::EXTENSOES[$ext]];
            }
            if (count($saida) > self::MAX_ARQUIVOS) {
                throw new RuntimeException('Arquivos demais (máximo '.self::MAX_ARQUIVOS.' documentos por admissão).');
            }
            if ($total > self::MAX_TOTAL) {
                throw new RuntimeException('Os documentos passam do tamanho máximo permitido (150 MB).');
            }
        }
        if (! $saida) {
            throw new RuntimeException('Nenhum documento suportado encontrado (PDF, JPG, PNG ou WEBP — soltos ou dentro de um .zip).');
        }

        return $saida;
    }

    private static function abrirZip(string $caminho, string $nomeZip): array
    {
        $zip = new ZipArchive;
        if ($zip->open($caminho) !== true) {
            throw new RuntimeException("Não foi possível abrir o arquivo {$nomeZip} (zip inválido ou protegido por senha).");
        }
        $docs = [];
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $info = $zip->statIndex($i);
                $nome = str_replace('\\', '/', $info['name']);
                $base = basename($nome);
                if (str_ends_with($nome, '/') || str_starts_with($nome, '__MACOSX/') || str_starts_with($base, '.')) {
                    continue;   // pastas e arquivos de sistema
                }
                $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
                if (! isset(self::EXTENSOES[$ext])) {
                    continue;
                }
                if ($info['size'] > self::MAX_POR_ARQUIVO) {
                    throw new RuntimeException("O arquivo {$base} dentro do zip passa de 20 MB.");
                }
                if (count($docs) >= self::MAX_ARQUIVOS) {
                    throw new RuntimeException('O zip tem documentos demais (máximo '.self::MAX_ARQUIVOS.').');
                }
                $conteudo = $zip->getFromIndex($i, self::MAX_POR_ARQUIVO + 1);
                if ($conteudo === false || strlen($conteudo) > self::MAX_POR_ARQUIVO) {
                    throw new RuntimeException("Não foi possível ler {$base} dentro do zip.");
                }
                $docs[] = ['nome' => self::nomeSeguro($base), 'conteudo' => $conteudo, 'mime' => self::EXTENSOES[$ext]];
            }
        } finally {
            $zip->close();
        }
        if (! $docs) {
            throw new RuntimeException("O zip {$nomeZip} não tem documentos PDF, JPG, PNG ou WEBP.");
        }

        return $docs;
    }

    /** Só o nome do arquivo, sem caminho nem caracteres estranhos (o caminho real é gerado pelo portal). */
    public static function nomeSeguro(string $nome): string
    {
        $nome = basename(str_replace('\\', '/', $nome));
        $nome = preg_replace('/[^\pL\pN._\- ]/u', '_', $nome);

        return mb_substr($nome, 0, 120) ?: 'documento';
    }
}
