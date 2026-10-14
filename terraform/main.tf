terraform {
  required_version = ">= 1.6"
  required_providers {
    kubernetes = {
      source  = "hashicorp/kubernetes"
      version = "~> 2.23"
    }
  }
}

provider "kubernetes" {
  config_path    = "~/.kube/config"
  config_context = "docker-desktop" 
}

resource "kubernetes_namespace" "aulaviva" {
  metadata {
    name = "aulaviva-prod"
  }
}

resource "kubernetes_secret" "aulaviva_secrets" {
  metadata {
    name      = "aulaviva-secrets"
    namespace = kubernetes_namespace.aulaviva.metadata[0].name
  }

  data = {
    DATABASE_URL   = "placeholder"
    REDIS_HOST     = "placeholder"
    OPENAI_API_KEY = "placeholder"
  }
  type = "Opaque"
}
