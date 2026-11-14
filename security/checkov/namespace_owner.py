from checkov.common.models.enums import CheckResult, CheckCategories
from checkov.terraform.checks.resource.base_resource_check import BaseResourceCheck

class NamespaceOwner(BaseResourceCheck):
    def __init__(self):
        super().__init__(name="AulaViva namespace must declare ownership", id="CKV_AULAVIVA_1",
                         categories=[CheckCategories.GENERAL_SECURITY],
                         supported_resources=["kubernetes_namespace_v1"])

    def scan_resource_conf(self, conf):
        metadata = conf.get("metadata", [{}])[0]
        labels = metadata.get("labels", [{}])[0]
        return CheckResult.PASSED if labels.get("app.kubernetes.io/part-of") == "aulaviva" else CheckResult.FAILED

check = NamespaceOwner()
