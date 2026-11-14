from checkov.common.models.enums import CheckResult, CheckCategories
from checkov.terraform.checks.resource.base_resource_check import BaseResourceCheck

class DenyIngress(BaseResourceCheck):
    def __init__(self):
        super().__init__(name="AulaViva default deny must not allow ingress", id="CKV_AULAVIVA_2",
                         categories=[CheckCategories.NETWORKING],
                         supported_resources=["kubernetes_network_policy_v1"])

    def scan_resource_conf(self, conf):
        name = conf.get("metadata", [{}])[0].get("name", [""])[0]
        if name != "default-deny-ingress":
            return CheckResult.UNKNOWN
        spec = conf.get("spec", [{}])[0]
        types = spec.get("policy_types", [[]])[0]
        selector = spec.get("pod_selector", [{}])[0]
        valid = "Ingress" in types and not spec.get("ingress") and not selector.get("match_labels") and not selector.get("match_expressions")
        return CheckResult.PASSED if valid else CheckResult.FAILED

check = DenyIngress()
