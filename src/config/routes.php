<?php
/**
 * routes.php - Tabela única de rotas públicas (URL limpa => arquivo real)
 *
 * Usada pelo index.php da raiz. Para criar uma rota nova, adicione uma linha
 * aqui — não é preciso mexer no .htaccess nem na configuração do Apache.
 *
 * As rotas de página têm sempre um único nível (sem "/") porque os links do
 * site são relativos: é isso que permite instalar em subpasta ou sem
 * mod_rewrite. Só as rotas de API (que devolvem JSON) usam o prefixo "api/".
 */

return [

    // Autenticação (pública)
    'login'                                  => 'src/auth/login.html',
    'registrar'                              => 'src/auth/register.html',
    'esqueci-senha'                          => 'src/auth/esqueci_senha.html',
    'redefinir-senha'                        => 'src/auth/redefinir_senha.php',
    'verificar-email'                        => 'src/auth/verificar_email.php',
    'api/login'                              => 'src/auth/login_api.php',
    'api/logout'                             => 'src/auth/logout_api.php',
    'api/cadastrar-usuario'                  => 'src/auth/cadastrar_usuario.php',
    'api/esqueci-senha'                      => 'src/auth/esqueci_senha.php',
    'api/redefinir-senha'                    => 'src/auth/redefinir_senha_api.php',
    'api/verificar-2fa'                      => 'src/auth/verificar_2fa_api.php',

    // Páginas
    ''                                       => 'src/pages/index.html',
    'meus-projetos'                          => 'src/pages/meus-projetos.php',
    'explorar-projetos'                      => 'src/pages/explorar_projetos.php',
    'meus-dispositivos'                      => 'src/pages/meus-dispositivos.php',
    'perfil'                                 => 'src/pages/perfil.php',
    'configuracoes'                          => 'src/pages/configuracoes.php',
    'documentacao'                           => 'src/pages/documentacao.php',
    'termos'                                 => 'src/pages/termos.php',
    'novo-projeto'                           => 'src/pages/novo-projeto.php',
    'projeto'                                => 'src/pages/gerenciar-projeto.php',
    'dispositivo'                            => 'src/pages/gerenciar-dispositivo.php',
    'novo-dispositivo'                       => 'src/pages/novo-dispositivo.php',
    'ver-projeto'                            => 'src/pages/visualizar_projeto.php',
    'novo-grafico'                           => 'src/pages/criar_grafico_avancado.php',
    'admin-usuarios'                         => 'src/pages/admin-usuarios.php',
    'admin-rate-limiting'                    => 'src/pages/admin-rate-limiting.php',
    'admin'                                  => 'src/pages/admin-dashboard.php',

    // API (inclui endpoints que fisicamente moram em src/pages/)
    'api/listar-devices'                     => 'src/pages/listar_devices.php',
    'api/listar-graficos'                    => 'src/pages/listar_graficos.php',
    'api/listar-projetos-publicos'           => 'src/pages/listar_projetos_publicos.php',
    'api/listar-projetos'                    => 'src/pages/listar_projetos.php',
    'api/obter-projeto'                      => 'src/pages/obter_projeto.php',
    'api/obter-chaves-dispositivo'           => 'src/pages/obter_chaves_dispositivo.php',
    'api/obter-dados-grafico'                => 'src/pages/obter_dados_grafico.php',
    'api/obter-info-dispositivo'             => 'src/pages/obter_info_dispositivo.php',
    'api/cadastrar-device'                   => 'src/pages/cadastrar_device.php',
    'api/deletar-dispositivo'                => 'src/pages/deletar_dispositivo.php',
    'api/gerenciar-tags-dispositivo'         => 'src/pages/gerenciar_tags_dispositivo.php',
    'api/gerenciar-mapeamentos-dispositivo'  => 'src/pages/gerenciar_mapeamentos_dispositivo.php',
    'api/atualizar-grafico'                  => 'src/pages/atualizar_grafico.php',
    'api/salvar-grafico'                     => 'src/pages/salvar_grafico.php',
    'api/deletar-grafico'                    => 'src/pages/deletar_grafico.php',
    'api/criar-projeto'                      => 'src/pages/criar_projeto.php',

    // API (src/api/)
    'api/2fa-confirmar-configuracao'         => 'src/api/2fa_confirmar_configuracao.php',
    'api/2fa-desativar'                      => 'src/api/2fa_desativar.php',
    'api/2fa-iniciar-configuracao'           => 'src/api/2fa_iniciar_configuracao.php',
    'api/2fa-status'                         => 'src/api/2fa_status.php',
    'api/aceitar-convite'                    => 'src/api/aceitar_convite.php',
    'api/admin-usuarios'                     => 'src/api/admin_usuarios.php',
    'api/alterar-visibilidade-grafico'       => 'src/api/alterar_visibilidade_grafico.php',
    'api/alterar-visibilidade-projeto'       => 'src/api/alterar_visibilidade_projeto.php',
    'api/atualizar-grafico-avancado'         => 'src/api/atualizar_grafico_avancado.php',
    'api/atualizar-perfil'                   => 'src/api/atualizar_perfil.php',
    'api/atualizar-senha'                    => 'src/api/atualizar_senha.php',
    'api/buscar-payloads'                    => 'src/api/buscar_payloads.php',
    'api/deletar-foto-perfil'                => 'src/api/deletar_foto_perfil.php',
    'api/deletar-projeto'                    => 'src/api/deletar_projeto.php',
    'api/enviar-convite'                     => 'src/api/enviar_convite.php',
    'api/enviar-payload'                     => 'src/api/enviar_payload.php',
    'api/enviar-solicitacao-participacao'    => 'src/api/enviar_solicitacao_participacao.php',
    'api/exportar-dados-projeto'             => 'src/api/exportar_dados_projeto.php',
    'api/expulsar-participante'              => 'src/api/expulsar_participante.php',
    'api/listar-convites'                    => 'src/api/listar_convites.php',
    'api/listar-meus-dispositivos'           => 'src/api/listar_meus_dispositivos.php',
    'api/listar-participantes'               => 'src/api/listar_participantes.php',
    'api/listar-solicitacoes-participacao'   => 'src/api/listar_solicitacoes_participacao.php',
    'api/listar-tags'                        => 'src/api/listar_tags.php',
    'api/listar-usuarios'                    => 'src/api/listar_usuarios.php',
    'api/obter-contagem-participantes'       => 'src/api/obter_contagem_participantes.php',
    'api/obter-csrf-token'                   => 'src/api/obter_csrf_token.php',
    'api/obter-dados-grafico-avancado'       => 'src/api/obter_dados_grafico_avancado.php',
    'api/obter-dados-grafico-renderizado'    => 'src/api/obter_dados_grafico_renderizado.php',
    'api/obter-notificacoes'                 => 'src/api/obter_notificacoes.php',
    'api/obter-perfil-usuario'               => 'src/api/obter_perfil_usuario.php',
    'api/obter-stats-payloads'               => 'src/api/obter_stats_payloads.php',
    'api/promover-gerente'                   => 'src/api/promover_gerente.php',
    'api/recusar-convite'                    => 'src/api/recusar_convite.php',
    'api/responder-solicitacao-participacao' => 'src/api/responder_solicitacao_participacao.php',
    'api/sair-projeto'                       => 'src/api/sair_projeto.php',
    'api/salvar-grafico-avancado'            => 'src/api/salvar_grafico_avancado.php',
    'api/solicitacoes-perfil'                => 'src/api/solicitacoes_perfil.php',
    'api/ttn-webhook'                        => 'src/api/ttn_webhook.php',
    'api/upload-foto-perfil'                 => 'src/api/upload_foto_perfil.php',
];
