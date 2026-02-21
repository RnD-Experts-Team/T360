<template>
  <Dialog v-model:open="openProxy">
    <DialogContent
      class="max-w-[95vw] sm:max-w-[90vw] md:max-w-5xl max-h-[90vh] overflow-hidden flex flex-col"
    >
      <!-- Header -->
      <DialogHeader class="px-4 sm:px-6 border-b pb-3">
        <div class="flex items-center gap-2">
          <Icon name="upload" class="h-5 w-5 text-primary" />
          <DialogTitle class="text-lg sm:text-xl font-semibold">
            Import Rejections
          </DialogTitle>
        </div>
        <DialogDescription class="text-xs sm:text-sm mt-1 text-muted-foreground">
          Choose an import type, then upload your CSV file. The file will be validated before import.
        </DialogDescription>
      </DialogHeader>

      <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-4 space-y-5">

        <!-- ── Step 0 (Super Admin only): Choose Tenant ── -->
        <div v-if="isSuperAdmin" class="space-y-2">
          <p class="text-sm font-semibold flex items-center gap-2">
            <span class="flex h-5 w-5 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-primary-foreground">1</span>
            Select Company
          </p>
          <select
            v-model="selectedTenantId"
            class="select-base"
            :disabled="isValidating || isImporting"
            @change="resetFile"
          >
            <option :value="null" disabled>— Choose a company —</option>
            <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">
              {{ tenant.name }}
            </option>
          </select>
          <p v-if="!selectedTenantId" class="text-xs text-amber-600 dark:text-amber-400 flex items-center gap-1">
            <Icon name="alert-triangle" class="h-3 w-3" />
            You must select a company before uploading a file.
          </p>
        </div>

        <!-- ── Step 1: Choose Import Type ── -->
        <div class="space-y-2" :class="{ 'opacity-50 pointer-events-none': isSuperAdmin && !selectedTenantId }">
          <p class="text-sm font-semibold flex items-center gap-2">
            <span class="flex h-5 w-5 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-primary-foreground">
              {{ isSuperAdmin ? '2' : '1' }}
            </span>
            Import Type
          </p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <label
              v-for="option in importOptions"
              :key="option.value"
              class="flex items-start gap-3 rounded-md border p-3 cursor-pointer hover:bg-muted/20 transition-colors"
              :class="selectedType === option.value ? 'border-primary bg-primary/5' : 'border-border'"
            >
              <input
                type="radio"
                class="mt-1 accent-primary"
                :value="option.value"
                v-model="selectedType"
                :disabled="isValidating || isImporting || (isSuperAdmin && !selectedTenantId)"
                @change="resetFile"
              />
              <div class="space-y-0.5">
                <div class="text-sm font-medium">{{ option.label }}</div>
                <div class="text-xs text-muted-foreground">{{ option.description }}</div>
              </div>
            </label>
          </div>
        </div>

        <!-- ── Step 2: Upload ── -->
        <template v-if="selectedType && !validationResults && (!isSuperAdmin || selectedTenantId)">
          <div class="space-y-2">
            <p class="text-sm font-semibold flex items-center gap-2">
              <span class="flex h-5 w-5 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-primary-foreground">
                {{ isSuperAdmin ? '3' : '2' }}
              </span>
              Upload File
            </p>
          </div>

          <!-- Optional trips file for load_with_trips -->
          <div v-if="selectedType === 'load_with_trips'" class="rounded-md border p-3 bg-muted/10 space-y-1">
            <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wide">Optional: Trips file</p>
            <p class="text-xs text-muted-foreground">
              Upload a Trips CSV alongside the Loads CSV to auto-populate driver names.
            </p>
            <label class="cursor-pointer inline-flex items-center gap-2 text-sm text-primary hover:underline mt-1">
              <Icon name="file-plus" class="h-4 w-4" />
              {{ tripsFile ? tripsFile.name : 'Attach Trips CSV (optional)' }}
              <input
                ref="tripsFileInput"
                type="file"
                class="hidden"
                accept=".csv,text/csv"
                @change="onTripsFileChange"
                :disabled="isValidating"
              />
            </label>
            <button
              v-if="tripsFile"
              type="button"
              @click="tripsFile = null"
              class="ml-2 text-xs text-red-500 hover:underline"
            >
              Remove
            </button>
          </div>

          <!-- Trips only notice -->
          <div
            v-if="selectedType === 'trips_only'"
            class="rounded-md border border-amber-300 bg-amber-50 dark:bg-amber-900/10 p-3 text-xs text-amber-700 dark:text-amber-300"
          >
            This will upload a Trips CSV only to update driver names on existing load rejections.
            No new rejections will be created.
          </div>

          <!-- Dropzone -->
          <div
            class="flex flex-col items-center justify-center border-2 border-dashed rounded-lg p-8 bg-muted/10 transition-colors"
            :class="{
              'border-primary bg-primary/5': isDragging,
              'opacity-60 pointer-events-none': isValidating,
            }"
            @dragenter.prevent="onDragEnter"
            @dragover.prevent="onDragOver"
            @dragleave.prevent="onDragLeave"
            @drop.prevent="onDrop"
          >
            <Icon name="file-spreadsheet" class="h-12 w-12 text-muted-foreground mb-3" />
            <div class="text-center">
              <p class="text-sm font-medium">
                <span class="text-primary">Drag & drop</span> your CSV here
              </p>
              <p class="text-xs text-muted-foreground mt-1">or</p>
            </div>
            <label class="cursor-pointer mt-3">
              <span class="text-sm font-medium text-primary hover:underline">Choose CSV file</span>
              <input
                ref="mainFileInput"
                type="file"
                class="hidden"
                accept=".csv,text/csv"
                @change="onMainFileChange"
                :disabled="isValidating"
              />
            </label>
            <p v-if="selectedFile" class="mt-2 text-xs text-muted-foreground">
              Selected: <span class="font-medium text-foreground">{{ selectedFile.name }}</span>
            </p>
            <p v-else class="text-xs text-muted-foreground mt-2">CSV files only</p>
          </div>


          <!-- Validating spinner -->
          <div v-if="isValidating" class="flex items-center justify-center gap-2 py-4">
            <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-primary"></div>
            <span class="text-sm text-muted-foreground">Validating CSV file...</span>
          </div>
        </template>

        <!-- ── Step 3: Validation Results ── -->
        <template v-if="validationResults">

          <!-- Tenant reminder (super admin) -->
          <div v-if="isSuperAdmin && selectedTenantId" class="rounded-md border border-primary/30 bg-primary/5 px-3 py-2 text-xs text-primary flex items-center gap-2">
            <Icon name="building" class="h-4 w-4 flex-shrink-0" />
            Importing for: <span class="font-semibold ml-1">{{ selectedTenantName }}</span>
          </div>

          <!-- Header chips -->
          <div v-if="validationResults.headers?.length" class="rounded-lg border p-3">
            <div class="flex items-center justify-between mb-2">
              <span class="text-sm font-semibold">CSV Headers Detected</span>
              <span class="text-xs text-muted-foreground">{{ validationResults.headers.length }} columns</span>
            </div>
            <div class="flex flex-wrap gap-2">
              <span
                v-for="h in validationResults.headers"
                :key="h"
                class="rounded-full bg-muted px-2 py-0.5 text-xs"
              >{{ h }}</span>
            </div>
          </div>

          <!-- Header error -->
          <Alert v-if="validationResults.header_error" variant="destructive">
            <AlertTitle>Header Error</AlertTitle>
            <AlertDescription>{{ validationResults.header_error }}</AlertDescription>
          </Alert>

          <!-- Summary cards -->
          <div class="grid grid-cols-3 gap-4">
            <Card class="border-2">
              <CardContent class="p-4 text-center">
                <div class="text-2xl font-bold">{{ validationResults.summary.total }}</div>
                <div class="text-sm text-muted-foreground">Total Rows</div>
              </CardContent>
            </Card>
            <Card class="border-2 border-green-500/50 bg-green-50 dark:bg-green-900/10">
              <CardContent class="p-4 text-center">
                <div class="text-2xl font-bold text-green-600">{{ validationResults.summary.valid }}</div>
                <div class="text-sm text-muted-foreground">Valid</div>
              </CardContent>
            </Card>
            <Card class="border-2 border-red-500/50 bg-red-50 dark:bg-red-900/10">
              <CardContent class="p-4 text-center">
                <div class="text-2xl font-bold text-red-600">{{ validationResults.summary.invalid }}</div>
                <div class="text-sm text-muted-foreground">Invalid</div>
              </CardContent>
            </Card>
          </div>

          <!-- Trips only alert -->
          <Alert
            v-if="validationResults.trips_only"
            class="border-amber-300 bg-amber-50 dark:bg-amber-900/10"
          >
            <AlertTitle class="text-amber-700 dark:text-amber-300">Trips Only Mode</AlertTitle>
            <AlertDescription class="text-amber-600 dark:text-amber-400">
              Only driver names will be updated on existing load rejections.
              No new records will be created.
            </AlertDescription>
          </Alert>

          <!-- Validation errors -->
          <div v-if="validationResults.invalid?.length">
            <div class="flex items-center justify-between mb-3">
              <h3 class="text-base font-semibold text-red-600 flex items-center gap-2">
                <Icon name="alert-triangle" class="h-5 w-5" />
                Validation Errors ({{ validationResults.invalid.length }})
              </h3>
              <Button @click="downloadErrorReport" variant="outline" size="sm">
                <Icon name="download" class="h-4 w-4 mr-1" />
                Download Error Report
              </Button>
            </div>
            <div class="border rounded-lg overflow-hidden">
              <div class="max-h-72 overflow-y-auto">
                <Table>
                  <TableHeader class="sticky top-0 bg-background">
                    <TableRow>
                      <TableHead class="w-20">Row #</TableHead>
                      <TableHead>Preview</TableHead>
                      <TableHead>Errors</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    <TableRow v-for="row in validationResults.invalid" :key="row.rowNumber">
                      <TableCell class="font-medium text-sm">{{ row.rowNumber }}</TableCell>
                      <TableCell class="text-xs text-muted-foreground">
                        <div class="flex flex-wrap gap-x-3 gap-y-1">
                          <span v-for="p in row.preview || []" :key="p.key" class="whitespace-nowrap">
                            <span class="font-medium text-foreground">{{ p.label }}:</span> {{ p.value }}
                          </span>
                        </div>
                      </TableCell>
                      <TableCell>
                        <div v-for="(err, i) in row.errors || []" :key="i" class="text-xs text-red-600">
                          • {{ err }}
                        </div>
                      </TableCell>
                    </TableRow>
                  </TableBody>
                </Table>
              </div>
            </div>
          </div>

          <!-- Valid rows preview -->
          <div v-if="validationResults.valid?.length && !validationResults.trips_only">
            <h3 class="text-base font-semibold text-green-600 flex items-center gap-2 mb-3">
              <Icon name="check-circle" class="h-5 w-5" />
              Valid Rows Preview (first 5 of {{ validationResults.valid.length }})
            </h3>
            <div class="border rounded-lg overflow-hidden">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead class="w-20">Row #</TableHead>
                    <TableHead>Preview</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  <TableRow v-for="row in validationResults.valid.slice(0, 5)" :key="row.rowNumber">
                    <TableCell class="font-medium text-sm">{{ row.rowNumber }}</TableCell>
                    <TableCell class="text-xs">
                      <div class="flex flex-wrap gap-x-3 gap-y-1">
                        <span v-for="p in row.preview || []" :key="p.key" class="whitespace-nowrap">
                          <span class="font-medium">{{ p.label }}:</span> {{ p.value }}
                        </span>
                      </div>
                    </TableCell>
                  </TableRow>
                </TableBody>
              </Table>
            </div>
          </div>

          <!-- Re-upload -->
          <div class="flex justify-start">
            <Button @click="resetFile" variant="ghost" size="sm" class="text-muted-foreground">
              <Icon name="rotate_ccw" class="mr-2 h-4 w-4" />
              Upload a different file
            </Button>
          </div>
        </template>

      </div>

      <!-- Footer -->
      <div class="border-t px-4 sm:px-6 py-4 flex items-center justify-between gap-3 bg-background">
        <div class="text-xs text-muted-foreground">
          <span v-if="isSuperAdmin && selectedTenantName">
            Company: <span class="font-medium">{{ selectedTenantName }}</span>
          </span>
          <span v-if="selectedType" :class="{ 'ml-3': isSuperAdmin && selectedTenantName }">
            Mode: <span class="font-medium">{{ currentOptionLabel }}</span>
          </span>
        </div>
        <div class="flex gap-3">
          <Button @click="handleClose" variant="outline" :disabled="isImporting">Close</Button>
          <Button
            v-if="canImport"
            @click="confirmImport"
            variant="default"
            :disabled="isImporting"
          >
            <div v-if="isImporting" class="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2"></div>
            <Icon v-else name="check" class="h-4 w-4 mr-1" />
            {{
              isImporting
                ? 'Importing...'
                : validationResults?.trips_only
                  ? 'Update Driver Names'
                  : `Import ${validationResults?.summary?.valid ?? 0} Rows`
            }}
          </Button>
        </div>
      </div>

    </DialogContent>
  </Dialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, usePage }      from '@inertiajs/vue3';
import Icon    from '@/components/Icon.vue';
import Button  from '@/components/ui/button/Button.vue';
import { Alert, AlertDescription, AlertTitle }                           from '@/components/ui/alert';
import { Card, CardContent }                                             from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';

// ─── Props & Emits ────────────────────────────────────────────────────────────
const props = defineProps({
  open:         { type: Boolean, default: false },
  isSuperAdmin: { type: Boolean, default: false },
  tenantSlug:   { type: String,  default: null  },
  tenants:      { type: Array,   default: () => [] }, // ← pass all tenants from Index.vue
});

const emit = defineEmits(['update:open', 'success', 'error']);

const openProxy = computed({
  get: () => props.open,
  set: (v) => emit('update:open', v),
});

// ─── Import type options ──────────────────────────────────────────────────────
const importOptions = [
  {
    value:       'advanced_block',
    label:       'Advanced Block',
    description: 'Import advanced block rejections from a weekly block schedule CSV.',
  },
  {
    value:       'block',
    label:       'Block',
    description: 'Import rejected blocks from a block acceptance status CSV.',
  },
  {
    value:       'load',
    label:       'Load',
    description: 'Import rejected loads from a load status CSV.',
  },
  {
    value:       'load_with_trips',
    label:       'Load + Trips',
    description: 'Import rejected loads and attach a Trips CSV to auto-fill driver names.',
  },
  {
    value:       'trips_only',
    label:       'Trips Only',
    description: 'Upload a Trips CSV to update driver names on existing load rejections.',
  },
];

// ─── State ────────────────────────────────────────────────────────────────────
const selectedTenantId  = ref<number | null>(null);
const selectedType      = ref<string>('');
const selectedFile      = ref<File | null>(null);
const tripsFile         = ref<File | null>(null);
const validationResults = ref<any>(null);
const isValidating      = ref(false);
const isImporting       = ref(false);
const isDragging        = ref(false);
const mainFileInput     = ref<HTMLInputElement | null>(null);
const tripsFileInput    = ref<HTMLInputElement | null>(null);
let dragDepth = 0;

const page = usePage();

// ─── Computed ─────────────────────────────────────────────────────────────────
const currentOptionLabel = computed(() =>
  importOptions.find(o => o.value === selectedType.value)?.label ?? ''
);

const selectedTenantName = computed(() => {
  if (!props.isSuperAdmin || !selectedTenantId.value) return '';
  return (props.tenants as any[]).find(t => t.id === selectedTenantId.value)?.name ?? '';
});

const templateUrl = computed(() => '/storage/upload-data-temps/Rejections Template.csv');

const canImport = computed(() => {
  if (!validationResults.value)                       return false;
  if (validationResults.value.header_error)           return false;
  if (props.isSuperAdmin && !selectedTenantId.value)  return false;
  if (validationResults.value.trips_only)             return true;
  return (validationResults.value.summary?.valid   ?? 0) > 0
      && (validationResults.value.summary?.invalid ?? 0) === 0;
});

// ─── Route helpers ────────────────────────────────────────────────────────────
const ROUTE_MAP: Record<string, { validate: string; confirm: string }> = {
  advanced_block: {
    validate: 'acceptance.validateAdvancedBlockImport',
    confirm:  'acceptance.confirmAdvancedBlockImport',
  },
  block: {
    validate: 'acceptance.validateBlockImport',
    confirm:  'acceptance.confirmBlockImport',
  },
  load:           { validate: 'acceptance.validateLoadImport', confirm: 'acceptance.confirmLoadImport' },
  load_with_trips:{ validate: 'acceptance.validateLoadImport', confirm: 'acceptance.confirmLoadImport' },
  trips_only:     { validate: 'acceptance.validateLoadImport', confirm: 'acceptance.confirmLoadImport' },
};

function getRoute(action: 'validate' | 'confirm'): string {
  const base   = ROUTE_MAP[selectedType.value]?.[action];
  if (!base) throw new Error(`No route for type: ${selectedType.value}`);
  const name   = props.isSuperAdmin ? `${base}.admin` : base;
  const params = props.isSuperAdmin ? {} : { tenantSlug: props.tenantSlug };
  return route(name, params);
}

// ─── File handling ────────────────────────────────────────────────────────────
function resetFile() {
  selectedFile.value      = null;
  tripsFile.value         = null;
  validationResults.value = null;
  isValidating.value      = false;
  isDragging.value        = false;
  dragDepth               = 0;
  if (mainFileInput.value)  mainFileInput.value.value  = '';
  if (tripsFileInput.value) tripsFileInput.value.value = '';
}

function onMainFileChange(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0];
  if (file) triggerValidation(file);
  (e.target as HTMLInputElement).value = '';
}

function onTripsFileChange(e: Event) {
  tripsFile.value = (e.target as HTMLInputElement).files?.[0] ?? null;
}

function onDragEnter() { dragDepth++; isDragging.value = true; }
function onDragOver()  { isDragging.value = true; }
function onDragLeave() { dragDepth--; if (dragDepth <= 0) { dragDepth = 0; isDragging.value = false; } }
function onDrop(e: DragEvent) {
  dragDepth = 0; isDragging.value = false;
  const file = e.dataTransfer?.files?.[0];
  if (file) triggerValidation(file);
}

// ─── Validation ───────────────────────────────────────────────────────────────
function triggerValidation(file: File) {
  const isCsv = file.type === 'text/csv' || file.name.toLowerCase().endsWith('.csv');
  if (!isCsv) { emit('error', 'Please upload a valid CSV file.'); return; }

  selectedFile.value = file;
  isValidating.value = true;

  const data = new FormData();

  // Always send tenant_id for super admin
  if (props.isSuperAdmin && selectedTenantId.value) {
    data.append('tenant_id', String(selectedTenantId.value));
  }

  if (selectedType.value === 'trips_only') {
    data.append('trips_file', file);
  } else {
    data.append('file', file);
    if (selectedType.value === 'load_with_trips' && tripsFile.value) {
      data.append('trips_file', tripsFile.value);
    }
  }

  router.post(getRoute('validate'), data, {
    forceFormData:  true,
    preserveScroll: true,
    onFinish: () => { isValidating.value = false; },
    onError:  () => { isValidating.value = false; emit('error', 'Failed to validate CSV.'); },
  });
}

// ─── Confirm import ───────────────────────────────────────────────────────────
function confirmImport() {
  isImporting.value = true;

  // Send tenant_id in the confirm request too so the session can be verified
  const payload: Record<string, any> = {};
  if (props.isSuperAdmin && selectedTenantId.value) {
    payload.tenant_id = selectedTenantId.value;
  }

  router.post(getRoute('confirm'), payload, {
    preserveScroll: true,
    onSuccess: () => {
      const count = validationResults.value?.summary?.valid ?? 0;
      emit('success', `Successfully imported ${count} rejection(s) for ${selectedTenantName.value || 'company'}.`);
      handleClose();
    },
    onError:  () => emit('error', 'Import failed. Please try again.'),
    onFinish: () => { isImporting.value = false; },
  });
}

// ─── Error report ─────────────────────────────────────────────────────────────
function downloadErrorReport() {
  const name   = props.isSuperAdmin ? 'acceptance.downloadErrorReport.admin' : 'acceptance.downloadErrorReport';
  const params = props.isSuperAdmin ? {} : { tenantSlug: props.tenantSlug };
  window.location.href = route(name, params);
}

// ─── Close ────────────────────────────────────────────────────────────────────
function handleClose() {
  openProxy.value = false;
}

// Reset all state on close
watch(openProxy, (isOpen) => {
  if (!isOpen) {
    selectedTenantId.value  = null;
    selectedType.value      = '';
    selectedFile.value      = null;
    tripsFile.value         = null;
    validationResults.value = null;
    isValidating.value      = false;
    isImporting.value       = false;
    isDragging.value        = false;
    dragDepth               = 0;
  }
});

// ─── Flash: receive validation results ───────────────────────────────────────
watch(
  () => (page.props as any).flash?.importValidation,
  (payload) => {
    if (!payload) return;
    isValidating.value = false;
    if (payload.results) {
      validationResults.value = { ...payload.results };
      if (payload.header_error) validationResults.value.header_error = payload.header_error;
      if (payload.trips_only)   validationResults.value.trips_only   = true;
    } else if (payload.message) {
      emit('error', payload.message);
    }
  },
  { immediate: true }
);
</script>

<style scoped>
.select-base {
  @apply flex h-10 w-full items-center rounded-md border border-input bg-background
         px-3 py-2 text-sm ring-offset-background appearance-none
         focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2
         disabled:cursor-not-allowed disabled:opacity-50;
}
</style>
