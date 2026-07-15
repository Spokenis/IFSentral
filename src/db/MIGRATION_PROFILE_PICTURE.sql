-- Migration: Adicionar Foto de Perfil aos Usuários
-- Data: 18/02/2026
-- Descrição: Adiciona campo profile_picture na tabela users para armazenar o caminho da foto de perfil

-- Adicionar coluna profile_picture na tabela users
ALTER TABLE `users` 
ADD COLUMN `profile_picture` VARCHAR(255) NULL DEFAULT NULL AFTER `username`;

-- Comentário sobre a coluna
-- profile_picture: Caminho relativo da foto de perfil do usuário (ex: uploads/profile/user_123.jpg)
-- NULL = sem foto (usa avatar padrão)
