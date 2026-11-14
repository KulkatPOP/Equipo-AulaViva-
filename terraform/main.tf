terraform {
  required_version = ">= 1.6.0, < 2.0.0"
  required_providers {
    kubernetes = {
      source  = "hashicorp/kubernetes"
      version = "~> 2.38"
    }
  }
}
provider "kubernetes" {
  config_path = var.kubeconfig_path
}
variable "kubeconfig_path" {
  type    = string
  default = "~/.kube/config"
}
resource "kubernetes_namespace_v1" "aulaviva" {
  metadata {
    name = "aulaviva"
    labels = {
      "app.kubernetes.io/part-of" = "aulaviva"
    }
  }
}
# IaC real del namespace del equipo. La aplicación sigue administrada por GitOps.
resource "kubernetes_network_policy_v1" "default_deny" {
  metadata {
    name      = "default-deny-ingress"
    namespace = kubernetes_namespace_v1.aulaviva.metadata[0].name
  }
  spec {
    pod_selector {}
    policy_types = ["Ingress"]
  }
}
resource "kubernetes_network_policy_v1" "web" {
  metadata {
    name      = "allow-ingress-controller"
    namespace = kubernetes_namespace_v1.aulaviva.metadata[0].name
  }
  spec {
    pod_selector {
      match_labels = { "app" = "aulaviva" }
    }
    policy_types = ["Ingress"]
    ingress {
      from {
        namespace_selector {
          match_labels = { "kubernetes.io/metadata.name" = "ingress-nginx" }
        }
      }
      ports {
        port     = "80"
        protocol = "TCP"
      }
    }
  }
}
