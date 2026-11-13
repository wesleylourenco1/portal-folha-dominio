<?php

namespace App\Services;

use Anthropic\Client as AnthropicClient;
use App\Models\Configuracao;
use App\Support\CamposAdmissao;
use RuntimeException;

/**
 * Extrai os dados do funcionário dos documentos (RG, CPF, CTPS...) usando IA.
 * O provedor (Claude ou OpenAI), o modelo e a chave são escolhidos em Configurações.
 */
class ExtratorDocumentos
{
    public const PROVEDORES = [
        'anthropic' => ['nome' => 'Claude (Anthropic)', 'modelo_padrao' => 'claude-opus-5-5', 'site' => 'console.anthropic.com'],
        'openai' => ['nome' => 'OpenAI (ChatGPT)', 'modelo_padrao' => 'gpt-5.6', 'site' => 'platform.openai.com'],
    ];

    private const IMAGENS = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    /** @param  array<int, array{nome: string, caminho: string}>  $arquivos */
    public function extrair(array $arquivos): array
    {
        [$provedor, $modelo, $chave] = $this->configuracao();
        $lidos = $this->lerArquivos($arquivos);
        $resultado = $provedor === 'openai'
            ? $this->openai($lidos, $modelo, $chave)
            : $this->anthropic($lidos, $modelo, $chave);
        $resultado['_ia'] = self::PROVEDORES[$provedor]['nome'].' / '.$modelo;

        return $resultado;
    }

    public function configuracao(): array
    {
        $provedor = Configuracao::ler('ia_provedor', 'anthropic');
        $provedor = isset(self::PROVEDORES[$provedor]) ? $provedor : 'anthropic';
        $modelo = Configuracao::ler('ia_modelo') ?: self::PROVEDORES[$provedor]['modelo_padrao'];
        $chave = Configuracao::ler('ia_chave') ?: env($provedor === 'openai' ? 'OPENAI_API_KEY' : 'ANTHROPIC_API_KEY');
        if (! $chave) {
            throw new RuntimeException('Nenhuma chave de API configurada. Vá em Configurações e informe a chave.');
        }

        return [$provedor, $modelo, $chave];
    }

    /** Valida a chave listando os modelos (não gera custo). */
    public function testarConexao(string $provedor, string $chave): string
    {
        if ($provedor === 'openai') {
            \OpenAI::client($chave)->models()->list();
        } else {
            (new AnthropicClient(apiKey: $chave))->models->list();
        }

        return 'Conexão OK: a chave foi aceita.';
    }

    public static function instrucoes(): string
    {
        $campos = collect(CamposAdmissao::chaves('doc'))
            ->map(fn ($c) => "- {$c}: ".CamposAdmissao::rotulos()[$c])->implode("\n");

        return <<<TXT
        Você recebe documentos pessoais de um funcionário recém-contratado no Brasil
        (RG, CNH, CPF, CTPS física ou digital, título de eleitor, comprovante de residência,
        certidão de nascimento/casamento, cartão PIS/extrato NIS, reservista, dados bancários etc.).

        Extraia os campos abaixo exatamente como aparecem nos documentos. Regras:
        - Datas no formato DD/MM/AAAA.
        - CPF como 000.000.000-00; PIS como 000.00000.00-0; CEP como 00000-000.
        - Nomes em MAIÚSCULAS, sem abreviar.
        - Sexo: M ou F. UF: sigla de 2 letras.
        - Campo que não aparece em nenhum documento: string vazia "". Nunca invente nem deduza.
        - Se dois documentos divergirem (ex.: endereço), use o mais recente e anote em "observacoes_extracao".
        - Liste em "campos_incertos" os campos cuja leitura ficou duvidosa (imagem borrada, cortada, manuscrita).

        Campos:
        {$campos}
        TXT;
    }

    public static function schema(): array
    {
        $doc = CamposAdmissao::chaves('doc');
        $props = array_fill_keys($doc, ['type' => 'string']) + [
            'documentos_identificados' => ['type' => 'array', 'items' => ['type' => 'string']],
            'campos_incertos' => ['type' => 'array', 'items' => ['type' => 'string']],
            'observacoes_extracao' => ['type' => 'string'],
        ];

        return ['type' => 'object', 'properties' => $props, 'required' => array_keys($props), 'additionalProperties' => false];
    }

    private function lerArquivos(array $arquivos): array
    {
        $saida = [];
        foreach ($arquivos as $a) {
            $ext = strtolower(pathinfo($a['nome'], PATHINFO_EXTENSION));
            if ($ext === 'pdf') {
                $saida[] = [$a['nome'], 'pdf', 'application/pdf', base64_encode(file_get_contents($a['caminho']))];
            } elseif (isset(self::IMAGENS[$ext])) {
                $saida[] = [$a['nome'], 'imagem', self::IMAGENS[$ext], base64_encode($this->reduzirImagem($a['caminho'], self::IMAGENS[$ext]))];
            }
        }
        if (! $saida) {
            throw new RuntimeException('Nenhum arquivo suportado (use PDF, JPG, PNG ou WEBP).');
        }

        return $saida;
    }

    /** Reduz fotos grandes de celular para no máximo 2000px (mantém legibilidade, economiza tokens). */
    private function reduzirImagem(string $caminho, string $mime): string
    {
        $bytes = file_get_contents($caminho);
        if (! function_exists('imagecreatefromstring') || ! ($img = @imagecreatefromstring($bytes))) {
            return $bytes;
        }
        [$w, $h] = [imagesx($img), imagesy($img)];
        if (max($w, $h) > 2000) {
            $f = 2000 / max($w, $h);
            $img = imagescale($img, (int) ($w * $f), (int) ($h * $f));
        }
        ob_start();
        $mime === 'image/png' ? imagepng($img) : imagejpeg($img, null, 90);

        return ob_get_clean();
    }

    private function anthropic(array $lidos, string $modelo, string $chave): array
    {
        $conteudo = [];
        foreach ($lidos as [$nome, $tipo, $mime, $dados]) {
            $conteudo[] = ['type' => 'text', 'text' => "Arquivo: {$nome}"];
            $conteudo[] = $tipo === 'pdf'
                ? ['type' => 'document', 'title' => $nome, 'source' => ['type' => 'base64', 'mediaType' => $mime, 'data' => $dados]]
                : ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mime, 'data' => $dados]];
        }
        $conteudo[] = ['type' => 'text', 'text' => 'Extraia os dados conforme as instruções.'];

        $outputConfig = ['format' => ['type' => 'json_schema', 'schema' => self::schema()]];
        if (preg_match('/^claude-(opus|fable|sonnet-5|haiku-5)/', $modelo)) {
            $outputConfig['effort'] = 'medium';
        }
        $resposta = (new AnthropicClient(apiKey: $chave))->messages->create(
            model: $modelo,
            maxTokens: 16000,
            system: self::instrucoes(),
            outputConfig: $outputConfig,
            messages: [['role' => 'user', 'content' => $conteudo]],
        );

        if ($resposta->stopReason === 'refusal') {
            throw new RuntimeException('A leitura foi recusada pelo modelo. Preencha manualmente.');
        }
        if ($resposta->stopReason === 'max_tokens') {
            throw new RuntimeException('Resposta incompleta (limite de tokens). Tente com menos arquivos.');
        }
        foreach ($resposta->content as $bloco) {
            if ($bloco->type === 'text') {
                return json_decode($bloco->text, true, flags: JSON_THROW_ON_ERROR);
            }
        }
        throw new RuntimeException('A IA não retornou dados.');
    }

    private function openai(array $lidos, string $modelo, string $chave): array
    {
        $conteudo = [];
        foreach ($lidos as [$nome, $tipo, $mime, $dados]) {
            $conteudo[] = ['type' => 'input_text', 'text' => "Arquivo: {$nome}"];
            $conteudo[] = $tipo === 'pdf'
                ? ['type' => 'input_file', 'filename' => $nome, 'file_data' => "data:{$mime};base64,{$dados}"]
                : ['type' => 'input_image', 'detail' => 'high', 'image_url' => "data:{$mime};base64,{$dados}"];
        }
        $conteudo[] = ['type' => 'input_text', 'text' => 'Extraia os dados conforme as instruções.'];

        $resposta = \OpenAI::client($chave)->responses()->create([
            'model' => $modelo,
            'instructions' => self::instrucoes(),
            'input' => [['role' => 'user', 'content' => $conteudo]],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'dados_funcionario', 'schema' => self::schema(), 'strict' => true]],
        ]);
        if (($resposta->status ?? '') === 'incomplete' || ! $resposta->outputText) {
            throw new RuntimeException('Resposta incompleta da OpenAI. Tente com menos arquivos.');
        }

        return json_decode($resposta->outputText, true, flags: JSON_THROW_ON_ERROR);
    }
}
