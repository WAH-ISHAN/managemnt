import { PanelError } from "@/components/portal/panel-error";
import { authed } from "@/lib/api";
import { Badge, Card, CardBody, CardHeader, EmptyState, PageHeading, Table, TBody, TD, TH, THead, TR } from "@/components/console/ui";
import { InfrastructureRefresh } from "@/components/portal/infrastructure-refresh";
import { InfrastructureMaintenance } from "@/components/portal/infrastructure-maintenance";
import { Activity, Box, Cpu, Database, HardDrive, MemoryStick, Server, type LucideIcon } from "lucide-react";

export const metadata = { title: "Infrastructure - TenderHub Console" };
type Metric = { load_1m?: number | null; cpu_count?: number | null; memory_used_percent?: number | null; disk_used_percent?: number | null };
type Container = { name?: string; image?: string; state?: string; health?: string | null };
type Process = { pid?: number; command?: string; cpu_percent?: number; memory_percent?: number };
type Node = { name: string; ready: string };
type Infra = { checked_at: string; observer_age_seconds: number; host: Metric; orchestrator: string; containers: Container[]; processes: Process[]; kubernetes: { available: boolean; nodes: Node[]; message?: string | null }; warnings: string[] };
function percent(value: unknown) { return value === null || value === undefined ? "-" : `${Number(value).toFixed(1)}%`; }
function tone(state: string) { return state === "running" || state === "healthy" ? "green" : state === "exited" || state === "dead" ? "red" : "yellow"; }

export default async function InfrastructurePage() {
  const [response, maintenance] = await Promise.all([
    authed<Infra>("/api/v1/admin/infrastructure/status"),
    authed<{ available?: boolean; reason?: string; status?: Record<string, unknown> }>("/api/v1/admin/infrastructure/maintenance"),
  ]);
  if (!response.ok) return <PanelError status={response.status} />;
  const data = response.body?.data as Infra | undefined;
  if (!data) return <PanelError status={503} />;
  const metrics: { icon: LucideIcon; label: string; value: string }[] = [
    { icon: Cpu, label: "CPU load", value: data.host.load_1m == null ? "-" : `${Number(data.host.load_1m).toFixed(2)} / ${data.host.cpu_count ?? "-"}` },
    { icon: MemoryStick, label: "RAM used", value: percent(data.host.memory_used_percent) },
    { icon: HardDrive, label: "Docker disk", value: percent(data.host.disk_used_percent) },
    { icon: Server, label: "Orchestrator", value: data.orchestrator },
  ];
  return <div className="space-y-8 pb-12">
    <PageHeading title="Infrastructure" description="Read-only live telemetry from the TenderHub VPS. No synthetic metrics and no destructive Docker or Kubernetes controls." meta={<span>Checked {new Date(data.checked_at).toLocaleString("en-LK")}</span>} actions={<InfrastructureRefresh />} />
    {data.observer_age_seconds > 150 ? <div role="alert" className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">VPS observer data is stale ({data.observer_age_seconds}s old). Check the host cron and observer log.</div> : null}
    {data.warnings.length ? <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900"><p className="font-semibold">Live warnings</p><ul className="mt-2 list-disc pl-5">{data.warnings.map((warning: string) => <li key={warning}>{warning}</li>)}</ul></div> : null}
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">{metrics.map(({ icon: Icon, label, value }) => <Card key={label}><CardBody><div className="flex items-center gap-3"><Icon className="size-5 text-primary-700" aria-hidden="true" /><div><p className="text-sm text-gray-500">{label}</p><p className="mt-1 text-xl font-semibold text-gray-900">{value}</p></div></div></CardBody></Card>)}</div>
    <InfrastructureMaintenance initial={maintenance.ok ? (maintenance.body?.data ?? null) : null} />
    <Card><CardHeader title="Docker containers" description="Inventory from the host Docker daemon. Management actions remain disabled to prevent accidental production changes." />{data.containers.length ? <Table><THead><TH>Name</TH><TH>Image</TH><TH>Status</TH><TH>Health</TH></THead><TBody>{data.containers.map((container: Container) => <TR key={container.name}><TD strong>{container.name ?? "-"}</TD><TD className="max-w-xs truncate">{container.image ?? "-"}</TD><TD><Badge tone={tone(container.state ?? "unknown")} dot>{container.state ?? "unknown"}</Badge></TD><TD>{container.health ?? "Not reported"}</TD></TR>)}</TBody></Table> : <CardBody><EmptyState icon={Box} title="No Docker containers were reported." /></CardBody>}</Card>
    <div className="grid gap-6 xl:grid-cols-2">
      <Card><CardHeader title="Top host processes" description="Current host process sample sorted by CPU usage." />{data.processes.length ? <Table><THead><TH>PID</TH><TH>Process</TH><TH>CPU</TH><TH>RAM</TH></THead><TBody>{data.processes.map((process: Process) => <TR key={`${process.pid}-${process.command}`}><TD>{process.pid}</TD><TD>{process.command}</TD><TD>{percent(process.cpu_percent)}</TD><TD>{percent(process.memory_percent)}</TD></TR>)}</TBody></Table> : <CardBody><EmptyState icon={Activity} title="No process data reported." /></CardBody>}</Card>
      <Card><CardHeader title="Kubernetes" description="Read-only detection only. This VPS currently runs the Docker Compose deployment." />{data.kubernetes.available ? <CardBody><p className="text-sm text-gray-700">Kubernetes API is available.</p><ul className="mt-4 space-y-2">{data.kubernetes.nodes.map((node: Node) => <li key={node.name} className="flex justify-between rounded border p-3 text-sm"><span>{node.name}</span><Badge tone={node.ready === "True" ? "green" : "yellow"}>{node.ready}</Badge></li>)}</ul></CardBody> : <CardBody><EmptyState icon={Database} title="Kubernetes is not configured." description={data.kubernetes.message ?? "No kubectl cluster was detected on the VPS."} /></CardBody>}</Card>
    </div>
  </div>;
}
