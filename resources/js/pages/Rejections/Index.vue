<template>
  <AppLayout
    :breadcrumbs="breadcrumbs"
    :tenantSlug="tenantSlug"
    :permissions="props.permissions"
  >
    <Head title="Acceptance" />

    <div
      class="w-full md:max-w-2xl lg:max-w-3xl xl:max-w-6xl lg:mx-auto m-0 p-2 md:p-4 lg:p-6 space-y-2 md:space-y-4 lg:space-y-6"
    >
      <!-- Success Message -->
      <Alert v-if="successMessage" variant="success">
        <AlertTitle>Success</AlertTitle>
        <AlertDescription>{{ successMessage }}</AlertDescription>
      </Alert>

      <!-- Error Message -->
      <Alert v-if="errorMessage" variant="destructive">
        <AlertTitle>Error</AlertTitle>
        <AlertDescription>{{ errorMessage }}</AlertDescription>
      </Alert>

      <!-- Actions Section -->
      <div
        class="mb-2 flex flex-col items-center justify-between px-2 sm:flex-row md:mb-4 lg:mb-6"
      >
        <h1
          class="text-lg font-bold text-gray-800 dark:text-gray-200 md:text-xl lg:text-2xl"
        >
          Acceptance
        </h1>

        <div class="flex flex-wrap gap-3 ml-3">
          <Button
            v-if="permissionNames.includes('acceptance.create')"
            class="px-2 py-0 md:px-4 md:py-2"
            @click="openForm()"
            variant="default"
          >
            <Icon name="plus" class="mr-1 h-4 w-4 md:mr-2" />
            Add Rejection
          </Button>

          <Button
            class="px-2 py-0 md:px-4 md:py-2"
            v-if="
              selectedRejections.length > 0 &&
              permissionNames.includes('acceptance.delete')
            "
            @click="confirmDeleteSelected()"
            variant="destructive"
          >
            <Icon name="trash" class="mr-1 h-4 w-4 md:mr-2" />
            Delete Selected ({{ selectedRejections.length }})
          </Button>

          <Button
            v-if="permissionNames.includes('acceptance.import')"
            variant="secondary"
            class="px-2 py-0 md:px-4 md:py-2 shadow-sm hover:shadow transition-all"
            @click="showImportModal = true"
          >
            <Icon name="upload" class="mr-1 h-4 w-4 md:mr-2" />
            Import CSV
          </Button>

          <Button
            class="px-2 py-0 md:px-4 md:py-2"
            @click.prevent="exportCSV"
            variant="outline"
            v-if="permissionNames.includes('acceptance.export')"
          >
            <Icon name="download" class="mr-1 h-4 w-4 md:mr-2" />
            Download CSV
          </Button>
        </div>
      </div>

      <!-- Hidden Export Form -->
      <form ref="exportForm" :action="exportUrl" method="GET" class="hidden"></form>

      <!-- Date Filter Tabs -->
      <Card>
        <CardContent class="p-2 md:p-4 lg:p-6">
          <div class="flex flex-col items-center gap-2 md:items-start">
            <div class="flex flex-wrap gap-1 md:gap-2">
              <Button
                @click="selectDateFilter('yesterday')"
                variant="outline"
                size="sm"
                :class="{
                  'border-primary bg-primary/10 text-primary': activeTab === 'yesterday',
                }"
              >
                Yesterday
              </Button>
              <Button
                @click="selectDateFilter('current-week')"
                variant="outline"
                size="sm"
                :class="{
                  'border-primary bg-primary/10 text-primary':
                    activeTab === 'current-week',
                }"
              >
                WTD
              </Button>
              <Button
                @click="selectDateFilter('6w')"
                variant="outline"
                size="sm"
                :class="{
                  'border-primary bg-primary/10 text-primary': activeTab === '6w',
                }"
              >
                T6W
              </Button>
              <Button
                @click="selectDateFilter('quarterly')"
                variant="outline"
                size="sm"
                :class="{
                  'border-primary bg-primary/10 text-primary': activeTab === 'quarterly',
                }"
              >
                Quarterly
              </Button>
            </div>

            <div v-if="props.dateRange" class="text-sm text-muted-foreground">
              <span v-if="activeTab === 'yesterday' && props.dateRange.start">
                Showing data from {{ formatDate(props.dateRange.start) }}
              </span>
              <span v-else-if="props.dateRange.start && props.dateRange.end">
                Showing data from {{ formatDate(props.dateRange.start) }} to
                {{ formatDate(props.dateRange.end) }}
              </span>
              <span v-else>{{ props.dateRange.label }}</span>
              <span v-if="weekNumberText" class="ml-1">({{ weekNumberText }})</span>
            </div>
          </div>
        </CardContent>
      </Card>

      <!-- Filters Section -->
      <Card class="mb-6">
        <CardHeader class="p-2 md:p-4 lg:p-6">
          <div class="flex items-center justify-between">
            <!-- Left: Title + reason toggle + active pills -->
            <div class="flex items-center gap-3 flex-wrap">
              <CardTitle class="text-lg md:text-xl lg:text-2xl">Filters</CardTitle>

              <!-- ── With/Without Reason toggle — always visible ── -->
              <div
                class="flex items-center rounded-lg border border-input bg-muted p-0.5 gap-0.5"
              >
                <button
                  @click="setReasonFilter('with_reason')"
                  :class="[
                    'rounded-md px-3 py-1 text-xs font-medium transition-all',
                    localFilters.rejectionReasonFilter === 'with_reason'
                      ? 'bg-background text-foreground shadow-sm'
                      : 'text-muted-foreground hover:text-foreground',
                  ]"
                >
                  Rejected
                </button>
                <button
                  @click="setReasonFilter('without_reason')"
                  :class="[
                    'rounded-md px-3 py-1 text-xs font-medium transition-all',
                    localFilters.rejectionReasonFilter === 'without_reason'
                      ? 'bg-background text-foreground shadow-sm'
                      : 'text-muted-foreground hover:text-foreground',
                  ]"
                >
                  Accepted
                </button>
                <button
                  @click="setReasonFilter('all')"
                  :class="[
                    'rounded-md px-3 py-1 text-xs font-medium transition-all',
                    localFilters.rejectionReasonFilter === 'all'
                      ? 'bg-background text-foreground shadow-sm'
                      : 'text-muted-foreground hover:text-foreground',
                  ]"
                >
                  All
                </button>
              </div>

              <!-- Active filter pills (when filters panel is collapsed) -->
              <div v-if="!showFilters && hasActiveFilters" class="flex flex-wrap gap-2">
                <span
                  v-if="localFilters.search"
                  class="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-semibold"
                >
                  Search: {{ localFilters.search }}
                </span>
                <span
                  v-if="localFilters.rejectionType"
                  class="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-semibold"
                >
                  Type: {{ rejectionTypeLabel(localFilters.rejectionType) }}
                </span>
                <span
                  v-if="localFilters.rejectionBucket"
                  class="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-semibold"
                >
                  Bucket: {{ bucketLabel(localFilters.rejectionBucket) }}
                </span>
                <span
                  v-if="localFilters.disputed"
                  class="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-semibold capitalize"
                >
                  Disputed: {{ localFilters.disputed }}
                </span>
                <span
                  v-if="localFilters.carrierControllable"
                  class="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-semibold"
                >
                  Carrier:
                  {{ localFilters.carrierControllable === "true" ? "Yes" : "No" }}
                </span>
                <span
                  v-if="localFilters.driverControllable"
                  class="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-semibold"
                >
                  Driver: {{ localFilters.driverControllable === "true" ? "Yes" : "No" }}
                </span>
                <span
                  v-if="localFilters.penaltyMin || localFilters.penaltyMax"
                  class="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-semibold"
                >
                  Penalty: {{ localFilters.penaltyMin || "0" }} –
                  {{ localFilters.penaltyMax || "∞" }}
                </span>
              </div>
            </div>

            <!-- Right: Show/Hide toggle -->
            <Button variant="ghost" size="sm" @click="showFilters = !showFilters">
              {{ showFilters ? "Hide Filters" : "Show Filters" }}
              <Icon
                :name="showFilters ? 'chevron-up' : 'chevron-down'"
                class="ml-2 h-4 w-4"
              />
            </Button>
          </div>
        </CardHeader>

        <CardContent v-if="showFilters" class="p-2 md:p-4 lg:p-6">
          <div class="flex flex-col gap-3 md:gap-4">
            <!-- Row 1: Search + Rejection Type + Bucket -->
            <div class="grid w-full grid-cols-1 gap-3 sm:grid-cols-3 md:gap-4">
              <div>
                <Label for="search">Search</Label>
                <Input
                  class="h-9 w-full lg:h-10"
                  id="search"
                  v-model="localFilters.search"
                  type="text"
                  placeholder="Driver name, Block ID, Load ID..."
                />
              </div>
              <div>
                <Label for="rejectionType">Rejection Type</Label>
                <select
                  id="rejectionType"
                  v-model="localFilters.rejectionType"
                  class="select-base"
                  @change="localFilters.rejectionBucket = ''"
                >
                  <option value="">All Types</option>
                  <option value="advanced_block">Advanced Block</option>
                  <option value="block">Block</option>
                  <option value="load">Load</option>
                </select>
              </div>
              <div>
                <Label for="rejectionBucket">Rejection Bucket</Label>
                <select
                  id="rejectionBucket"
                  v-model="localFilters.rejectionBucket"
                  class="select-base"
                >
                  <option value="">All Buckets</option>
                  <!-- Load buckets -->
                  <template
                    v-if="
                      localFilters.rejectionType === 'load' ||
                      localFilters.rejectionType === ''
                    "
                  >
                    <option value="rejected_after_start_time">After Start Time</option>
                    <option value="rejected_0_6_hours_before_start_time">
                      0–6 Hours Before
                    </option>
                    <option value="rejected_6_plus_hours_before_start_time">
                      6+ Hours Before
                    </option>
                  </template>
                  <!-- Block buckets -->
                  <template
                    v-if="
                      localFilters.rejectionType === 'block' ||
                      localFilters.rejectionType === ''
                    "
                  >
                    <option value="less_than_24">Less Than 24 Hours Before</option>
                    <option value="more_than_24">24+ Hours Before</option>
                  </template>
                </select>
              </div>
            </div>

            <!-- Row 2: Disputed + Carrier Controllable + Driver Controllable -->
            <div class="grid w-full grid-cols-1 gap-3 sm:grid-cols-3 md:gap-4">
              <div>
                <Label for="disputed">Disputed</Label>
                <select id="disputed" v-model="localFilters.disputed" class="select-base">
                  <option value="">All</option>
                  <option value="none">None</option>
                  <option value="pending">Pending</option>
                  <option value="won">Won</option>
                  <option value="lost">Lost</option>
                </select>
              </div>
              <div>
                <Label for="carrierControllable">Carrier Controllable</Label>
                <select
                  id="carrierControllable"
                  v-model="localFilters.carrierControllable"
                  class="select-base"
                >
                  <option value="">All</option>
                  <option value="true">Yes</option>
                  <option value="false">No</option>
                </select>
              </div>
              <div>
                <Label for="driverControllable">Driver Controllable</Label>
                <select
                  id="driverControllable"
                  v-model="localFilters.driverControllable"
                  class="select-base"
                >
                  <option value="">All</option>
                  <option value="true">Yes</option>
                  <option value="false">No</option>
                </select>
              </div>
            </div>

            <!-- Row 3: Penalty Range -->
            <div class="grid w-full grid-cols-1 gap-3 sm:grid-cols-2 md:gap-4">
              <div>
                <Label for="penaltyMin">Penalty Min</Label>
                <Input
                  class="h-9 w-full lg:h-10"
                  id="penaltyMin"
                  v-model="localFilters.penaltyMin"
                  type="number"
                  min="0"
                  placeholder="e.g. 0"
                />
              </div>
              <div>
                <Label for="penaltyMax">Penalty Max</Label>
                <Input
                  class="h-9 w-full lg:h-10"
                  id="penaltyMax"
                  v-model="localFilters.penaltyMax"
                  type="number"
                  min="0"
                  placeholder="e.g. 100"
                />
              </div>
            </div>

            <div class="flex justify-end space-x-2">
              <Button @click="resetFilters" variant="ghost" size="sm">
                <Icon name="rotate_ccw" class="mr-2 h-4 w-4" />
                Reset Filters
              </Button>
              <Button @click="applyFilters" variant="default" size="sm">
                <Icon name="filter" class="mr-2 h-4 w-4" />
                Apply Filters
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      <!-- Acceptance Dashboard -->
      <AcceptanceDashboard
        v-if="!props.isSuperAdmin"
        :metricsData="acceptanceMetrics || {}"
        :driversData="bottomDrivers || []"
        :chartData="acceptanceChartData || {}"
        :averageAcceptance="props.average_acceptance || null"
        :currentDateFilter="props.dateRange?.label || ''"
        :currentFilters="localFilters || {}"
      />

      <!-- Rejections Table -->
      <Card class="mx-auto max-w-[95vw] overflow-x-auto md:max-w-[64vw] lg:max-w-full">
        <CardContent class="p-0">
          <div class="overflow-x-auto">
            <Table class="relative h-[500px] overflow-auto">
              <TableHeader>
                <TableRow
                  class="sticky top-0 z-10 border-b bg-background hover:bg-background"
                >
                  <TableHead
                    class="w-[50px]"
                    v-if="permissionNames.includes('acceptance.delete')"
                  >
                    <div class="flex items-center justify-center">
                      <input
                        type="checkbox"
                        @change="toggleSelectAll"
                        :checked="isAllSelected"
                        class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                      />
                    </div>
                  </TableHead>

                  <TableHead v-if="props.isSuperAdmin" class="whitespace-nowrap"
                    >Company</TableHead
                  >

                  <TableHead
                    v-for="col in visibleColumns"
                    :key="col.key"
                    class="cursor-pointer whitespace-nowrap"
                    @click="sortBy(col.key)"
                  >
                    <div class="flex items-center gap-1">
                      {{ col.label }}
                      <span v-if="sortColumn === col.key">
                        <svg
                          v-if="sortDirection === 'asc'"
                          class="h-4 w-4"
                          viewBox="0 0 24 24"
                          fill="none"
                          stroke="currentColor"
                          stroke-width="2"
                        >
                          <path d="M8 15l4-4 4 4" />
                        </svg>
                        <svg
                          v-else
                          class="h-4 w-4"
                          viewBox="0 0 24 24"
                          fill="none"
                          stroke="currentColor"
                          stroke-width="2"
                        >
                          <path d="M16 9l-4 4-4-4" />
                        </svg>
                      </span>
                      <span v-else class="opacity-40">
                        <svg
                          class="h-4 w-4"
                          viewBox="0 0 24 24"
                          fill="none"
                          stroke="currentColor"
                          stroke-width="2"
                        >
                          <path d="M8 10l4-4 4 4" />
                          <path d="M16 14l-4 4-4-4" />
                        </svg>
                      </span>
                    </div>
                  </TableHead>

                  <TableHead
                    v-if="
                      permissionNames.includes('acceptance.update') ||
                      permissionNames.includes('acceptance.delete')
                    "
                  >
                    Actions
                  </TableHead>
                </TableRow>
              </TableHeader>

              <TableBody>
                <TableRow v-if="sortedRejections.length === 0">
                  <TableCell
                    :colspan="totalColspan"
                    class="py-8 text-center text-primary font-medium"
                  >
                    No rejections found matching your criteria
                  </TableCell>
                </TableRow>

                <TableRow
                  v-for="rejection in sortedRejections"
                  :key="rejection.id"
                  class="hover:bg-muted/50"
                >
                  <TableCell
                    class="text-center"
                    v-if="permissionNames.includes('acceptance.delete')"
                  >
                    <input
                      type="checkbox"
                      :value="rejection.id"
                      v-model="selectedRejections"
                      class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                    />
                  </TableCell>

                  <TableCell v-if="props.isSuperAdmin" class="whitespace-nowrap">
                    {{ rejection.tenant?.name || "—" }}
                  </TableCell>

                  <TableCell
                    v-for="col in visibleColumns"
                    :key="col.key"
                    class="whitespace-nowrap text-sm"
                  >
                    <CellValue :col="col.key" :rejection="rejection" />
                  </TableCell>

                  <TableCell
                    v-if="
                      permissionNames.includes('acceptance.delete') ||
                      permissionNames.includes('acceptance.update')
                    "
                  >
                    <div class="flex space-x-2">
                      <Button
                        size="sm"
                        @click="openForm(rejection)"
                        variant="warning"
                        v-if="permissionNames.includes('acceptance.update')"
                      >
                        <Icon name="pencil" class="mr-1 h-4 w-4" />
                        Edit
                      </Button>
                      <Button
                        size="sm"
                        variant="destructive"
                        @click="confirmDeleteRejection(rejection.id)"
                        v-if="permissionNames.includes('acceptance.delete')"
                      >
                        <Icon name="trash" class="mr-1 h-4 w-4" />
                        Delete
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </div>

          <!-- Pagination -->
          <div class="border-t bg-muted/20 px-4 py-3" v-if="props.rejections.links">
            <div class="flex flex-col items-center justify-between gap-2 sm:flex-row">
              <div class="flex items-center gap-4 text-sm text-muted-foreground">
                <span>
                  Showing {{ props.rejections.data.length }} of
                  {{ props.rejections.total }} entries
                </span>
                <div class="flex items-center gap-2">
                  <span class="text-sm">Show:</span>
                  <select
                    v-model="localPerPage"
                    @change="changePerPage"
                    class="h-8 rounded-md border border-input bg-background px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                  >
                    <option v-for="size in [10, 25, 50, 100]" :key="size" :value="size">
                      {{ size }}
                    </option>
                  </select>
                </div>
              </div>
              <div class="flex flex-wrap">
                <Button
                  v-for="link in props.rejections.links"
                  :key="link.label"
                  @click="visitPage(link.url)"
                  :disabled="!link.url"
                  variant="ghost"
                  size="sm"
                  class="mx-1"
                  :class="{ 'border-primary bg-primary/10 text-primary': link.active }"
                >
                  <span v-html="link.label"></span>
                </Button>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      <!-- Rejection Form Modal -->
      <Dialog v-model:open="formModal">
        <DialogContent class="max-w-[95vw] sm:max-w-[90vw] md:max-w-4xl">
          <DialogHeader class="px-4 sm:px-6">
            <DialogTitle class="text-lg sm:text-xl">
              {{ selectedRejection ? "Edit" : "Add" }} Rejection
            </DialogTitle>
            <DialogDescription class="text-xs sm:text-sm">
              Fill in the details to {{ selectedRejection ? "update" : "add" }} a
              rejection.
            </DialogDescription>
          </DialogHeader>
          <RejectionForm
            :rejection="normalizedRejection"
            :tenants="props.tenants"
            :is-super-admin="props.isSuperAdmin"
            :tenant-slug="props.tenantSlug"
            @close="formModal = false"
            @success="onFormSuccess"
            class="max-h-[75vh] overflow-y-auto p-4 sm:p-6"
          />
        </DialogContent>
      </Dialog>

      <!-- Bulk Delete Confirmation -->
      <Dialog v-model:open="showDeleteSelectedModal">
        <DialogContent class="max-w-[95vw] sm:max-w-md">
          <DialogHeader class="px-4 sm:px-6">
            <DialogTitle class="text-lg sm:text-xl">Confirm Bulk Deletion</DialogTitle>
            <DialogDescription class="text-xs sm:text-sm">
              Are you sure you want to delete {{ selectedRejections.length }} rejection
              records? This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter class="px-4 sm:px-6">
            <Button
              type="button"
              @click="showDeleteSelectedModal = false"
              variant="outline"
              >Cancel</Button
            >
            <Button
              type="button"
              @click="deleteSelectedRejections()"
              variant="destructive"
              >Delete Selected</Button
            >
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <!-- Single Delete Confirmation -->
      <Dialog v-model:open="showDeleteModal">
        <DialogContent class="max-w-[95vw] sm:max-w-md">
          <DialogHeader class="px-4 sm:px-6">
            <DialogTitle class="text-lg sm:text-xl">Confirm Deletion</DialogTitle>
            <DialogDescription class="text-xs sm:text-sm">
              Are you sure you want to delete this rejection record? This action cannot be
              undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter class="px-4 sm:px-6">
            <Button type="button" @click="showDeleteModal = false" variant="outline"
              >Cancel</Button
            >
            <Button
              type="button"
              @click="deleteRejection(rejectionToDelete)"
              variant="destructive"
              >Delete</Button
            >
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <!-- Import Modal -->
      <ImportRejectionModal
        v-model:open="showImportModal"
        :is-super-admin="props.isSuperAdmin"
        :tenant-slug="props.tenantSlug"
        :tenants="props.tenants"
        @success="onImportSuccess"
        @error="onImportError"
      />
    </div>
  </AppLayout>
</template>

<script setup>
import { Head, useForm, router } from "@inertiajs/vue3";
import { computed, defineComponent, h, onMounted, onUnmounted, ref, watch } from "vue";

import AcceptanceDashboard from "@/components/acceptance/AcceptanceDashboard.vue";
import ImportRejectionModal from "@/components/acceptance/ImportRejectionModal.vue";
import Icon from "@/components/Icon.vue";
import RejectionForm from "@/components/RejectionForm.vue";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import Button from "@/components/ui/button/Button.vue";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import AppLayout from "@/layouts/AppLayout.vue";

// ─── Props ────────────────────────────────────────────────────────────────────
const props = defineProps({
  rejections: { type: Object, default: () => ({ data: [], links: [], total: 0 }) },
  tenantSlug: { type: String, default: null },
  tenants: { type: Array, default: () => [] },
  isSuperAdmin: { type: Boolean, default: false },
  dateFilter: { type: String, default: "yesterday" },
  dateRange: { type: Object, default: () => ({}) },
  perPage: { type: Number, default: 10 },
  weekNumber: { type: Number, default: null },
  startWeekNumber: { type: Number, default: null },
  endWeekNumber: { type: Number, default: null },
  year: { type: Number, default: null },
  rejection_breakdown: { type: Object, default: null },
  line_chart_data: { type: Array, default: () => [] },
  average_acceptance: { type: Number, default: null },
  filters: {
    type: Object,
    default: () => ({
      search: "",
      rejectionType: "",
      rejectionBucket: "",
      disputed: "",
      carrierControllable: "",
      driverControllable: "",
      penaltyMin: "",
      penaltyMax: "",
      rejectionReasonFilter: "with_reason",
    }),
  },
  permissions: { type: Array, default: () => [] },
});

// ─── Local state ──────────────────────────────────────────────────────────────
const formModal = ref(false);
const selectedRejection = ref(null);
const errorMessage = ref("");
const successMessage = ref("");
const activeTab = ref(props.dateFilter || "quarterly");
const localPerPage = ref(props.perPage || 10);
const selectedRejections = ref([]);
const showDeleteSelectedModal = ref(false);
const showDeleteModal = ref(false);
const rejectionToDelete = ref(null);
const exportForm = ref(null);
const showFilters = ref(false);
const showImportModal = ref(false);
const sortColumn = ref("date");
const sortDirection = ref("desc");

// Local filters — hydrated from props so they survive page refreshes
const localFilters = ref({
  search: props.filters?.search ?? "",
  rejectionType: props.filters?.rejectionType ?? "",
  rejectionBucket: props.filters?.rejectionBucket ?? "",
  disputed: props.filters?.disputed ?? "",
  carrierControllable: props.filters?.carrierControllable ?? "",
  driverControllable: props.filters?.driverControllable ?? "",
  penaltyMin: props.filters?.penaltyMin ?? "",
  penaltyMax: props.filters?.penaltyMax ?? "",
  rejectionReasonFilter: props.filters?.rejectionReasonFilter ?? "with_reason",
});

// ─── Breadcrumbs ──────────────────────────────────────────────────────────────
const breadcrumbs = [
  {
    title: props.tenantSlug ? "Dashboard" : "Admin Dashboard",
    href: props.tenantSlug
      ? route("dashboard", { tenantSlug: props.tenantSlug })
      : route("admin.dashboard"),
  },
  {
    title: "Acceptance",
    href: props.tenantSlug
      ? route("acceptance.index", { tenantSlug: props.tenantSlug })
      : route("acceptance.index.admin"),
  },
];

// ─── Sub-relation accessors (hasMany → first item) ────────────────────────────
function ab(r) {
  return r.advanced_rejected_block?.[0] ?? null;
}
function rb(r) {
  return r.rejected_block?.[0] ?? null;
}
function rl(r) {
  return r.rejected_load?.[0] ?? null;
}

function getRejectionType(r) {
  if (ab(r)) return "advanced_block";
  if (rl(r)) return "load";
  if (rb(r)) return "block";
  return "unknown";
}

// ─── Labels / formatters ──────────────────────────────────────────────────────
function rejectionTypeLabel(type) {
  return (
    { advanced_block: "Advanced Block", block: "Block", load: "Load", unknown: "—" }[
      type
    ] ?? type
  );
}

function bucketLabel(bucket) {
  const map = {
    more_than_24: "24+ hours before start",
    less_than_24: "Less than 24 hours before start",
    rejected_after_start_time: "Rejected after start time",
    rejected_0_6_hours_before_start_time: "Rejected 0–6 hours before start",
    rejected_6_plus_hours_before_start_time: "Rejected 6+ hours before start",
  };
  return map[bucket] ?? bucket ?? "—";
}

// Short label used in filter pills
function bucketPillLabel(bucket) {
  const map = {
    more_than_24: "24+ Hours Before",
    less_than_24: "< 24 Hours Before",
    rejected_after_start_time: "After Start Time",
    rejected_0_6_hours_before_start_time: "0–6 Hours Before",
    rejected_6_plus_hours_before_start_time: "6+ Hours Before",
  };
  return map[bucket] ?? bucket ?? "—";
}

function formatDate(d) {
  if (!d) return "—";
  const str = String(d).split(" ")[0];
  const [y, m, day] = str.split("-");
  if (!y || !m || !day) return d;
  return `${Number(m)}/${Number(day)}/${y}`;
}

function formatDateTime(dt) {
  if (!dt) return "—";
  try {
    return new Date(dt).toLocaleString("en-US", {
      month: "numeric",
      day: "numeric",
      year: "numeric",
      hour: "numeric",
      minute: "2-digit",
      hour12: true,
    });
  } catch {
    return dt;
  }
}

// ─── ALL column definitions ───────────────────────────────────────────────────
const ALL_COLUMNS = [
  // ── Shared ──
  {
    key: "date",
    label: "Date",
    shared: true,
    getValue: (r) => formatDate(r.date),
  },
  {
    key: "type",
    label: "Type",
    shared: true,
    getValue: (r) => getRejectionType(r),
    render: (r) => {
      const type = getRejectionType(r);
      const cls =
        {
          advanced_block:
            "bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300",
          block: "bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300",
          load: "bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300",
          unknown: "bg-muted text-muted-foreground",
        }[type] ?? "bg-muted text-muted-foreground";
      return h(
        "span",
        {
          class: `inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${cls}`,
        },
        rejectionTypeLabel(type)
      );
    },
  },
  {
    key: "penalty",
    label: "Penalty",
    shared: true,

    getValue: (r) => {
      const isWon = r.disputed === "won";

      const isDriverControllable =
        r.driver_controllable === true ||
        r.driver_controllable === 1 ||
        r.driver_controllable === "1" ||
        r.driver_controllable === "true";

      const isCarrierControllable =
        r.carrier_controllable === true ||
        r.carrier_controllable === 1 ||
        r.carrier_controllable === "1" ||
        r.carrier_controllable === "true";

      // ✅ WON
      if (isWon) return 0;

      // ✅ Carrier NOT controllable → always 0
      if (!isCarrierControllable) return 0;

      return r.penalty != null ? Number(r.penalty) : null;
    },

    render: (r) => {
      const originalPenalty = r.penalty != null ? Number(r.penalty).toFixed(2) : null;

      const isWon = r.disputed === "won";

      const isDriverControllable =
        r.driver_controllable === true ||
        r.driver_controllable === 1 ||
        r.driver_controllable === "1" ||
        r.driver_controllable === "true";

      const isCarrierControllable =
        r.carrier_controllable === true ||
        r.carrier_controllable === 1 ||
        r.carrier_controllable === "1" ||
        r.carrier_controllable === "true";

      if (!originalPenalty && !isWon) {
        return h("span", { class: "text-muted-foreground" }, "—");
      }

      // ✅ WON + DRIVER CONTROLLABLE
      if (isWon && isDriverControllable) {
        return h("div", { class: "flex flex-col leading-tight" }, [
          h(
            "span",
            {
              class: "font-mono text-xs font-semibold text-green-600 dark:text-green-400",
            },
            "0.00"
          ),
          h(
            "span",
            {
              class: "self-end text-[10px] opacity-60 font-mono whitespace-nowrap",
            },
            `Driver: ${originalPenalty}`
          ),
        ]);
      }

      // ✅ WON (not driver controllable)
      if (isWon) {
        return h(
          "span",
          {
            class: "font-mono text-xs font-semibold text-green-600 dark:text-green-400",
          },
          "0.00"
        );
      }

      // ✅ Carrier NOT controllable
      if (!isCarrierControllable) {
        // If driver IS controllable → show driver note
        if (isDriverControllable && originalPenalty) {
          return h("div", { class: "flex flex-col leading-tight" }, [
            h(
              "span",
              {
                class:
                  "font-mono text-xs font-semibold text-green-600 dark:text-green-400",
              },
              "0.00"
            ),
            h(
              "span",
              {
                class: "self-end text-[10px] opacity-60 font-mono whitespace-nowrap",
              },
              `Driver: ${originalPenalty}`
            ),
          ]);
        }

        // If both false → just 0
        return h(
          "span",
          {
            class: "font-mono text-xs font-semibold text-green-600 dark:text-green-400",
          },
          "0.00"
        );
      }

      // ✅ Normal case
      return h("span", { class: "font-mono text-xs" }, originalPenalty);
    },
  },
  {
    key: "disputed",
    label: "Disputed",
    shared: true,
    getValue: (r) => r.disputed || null,
    render: (r) => {
      const cls =
        {
          none: "bg-muted text-muted-foreground",
          pending:
            "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300",
          won: "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300",
          lost: "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300",
        }[r.disputed] ?? "bg-muted text-muted-foreground";
      return h(
        "span",
        {
          class: `inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize ${cls}`,
        },
        r.disputed || "none"
      );
    },
  },
  {
    key: "carrier_controllable",
    label: "Carrier Ctrl.",
    shared: true,
    getValue: (r) =>
      r.carrier_controllable != null ? (r.carrier_controllable ? "Yes" : "No") : null,
  },
  {
    key: "driver_controllable",
    label: "Driver Ctrl.",
    shared: true,
    getValue: (r) =>
      r.driver_controllable != null ? (r.driver_controllable ? "Yes" : "No") : null,
  },
  {
    key: "rejection_reason",
    label: "Reason",
    shared: true,
    getValue: (r) => r.rejection_reason || null,
    render: (r) =>
      r.rejection_reason
        ? h(
            "span",
            { class: "block max-w-[180px] truncate", title: r.rejection_reason },
            r.rejection_reason
          )
        : h("span", { class: "text-muted-foreground" }, "—"),
  },

  // ── Advanced Block ──
  {
    key: "ab_rejection_id",
    label: "Adv. Rejection ID",
    shared: false,
    getValue: (r) => ab(r)?.advance_block_rejection_id ?? null,
  },
  {
    key: "ab_week_start",
    label: "Week Start",
    shared: false,
    getValue: (r) => (ab(r)?.week_start ? formatDate(ab(r).week_start) : null),
  },
  {
    key: "ab_week_end",
    label: "Week End",
    shared: false,
    getValue: (r) => (ab(r)?.week_end ? formatDate(ab(r).week_end) : null),
  },
  {
    key: "ab_impacted_blocks",
    label: "Impacted Blocks",
    shared: false,
    getValue: (r) =>
      ab(r)?.impacted_blocks != null ? String(ab(r).impacted_blocks) : null,
  },
  {
    key: "ab_expected_blocks",
    label: "Expected Blocks",
    shared: false,
    getValue: (r) =>
      ab(r)?.expected_blocks != null ? String(ab(r).expected_blocks) : null,
  },

  // ── Block ──
  {
    key: "b_block_id",
    label: "Block ID",
    shared: false,
    getValue: (r) => rb(r)?.block_id ?? null,
  },
  {
    key: "b_driver_name",
    label: "Driver Name",
    shared: false,
    getValue: (r) => rb(r)?.driver_name ?? null,
  },
  {
    key: "b_block_start",
    label: "Block Start",
    shared: false,
    getValue: (r) => (rb(r)?.block_start ? formatDateTime(rb(r).block_start) : null),
  },
  {
    key: "b_block_end",
    label: "Block End",
    shared: false,
    getValue: (r) => (rb(r)?.block_end ? formatDateTime(rb(r).block_end) : null),
  },
  {
    key: "b_rejection_datetime",
    label: "Rejection Time",
    shared: false,
    getValue: (r) =>
      rb(r)?.rejection_datetime ? formatDateTime(rb(r).rejection_datetime) : null,
  },
  {
    key: "b_rejection_bucket",
    label: "Bucket",
    shared: false,
    getValue: (r) =>
      rb(r)?.rejection_bucket ? bucketLabel(rb(r).rejection_bucket) : null,
  },

  // ── Load ──
  {
    key: "l_load_id",
    label: "Load ID",
    shared: false,
    getValue: (r) => rl(r)?.load_id ?? null,
  },
  {
    key: "l_driver_name",
    label: "Driver Name",
    shared: false,
    getValue: (r) => rl(r)?.driver_name ?? null,
  },
  {
    key: "l_origin_yard_arrival",
    label: "Yard Arrival",
    shared: false,
    getValue: (r) =>
      rl(r)?.origin_yard_arrival ? formatDateTime(rl(r).origin_yard_arrival) : null,
  },
  {
    key: "l_rejection_bucket",
    label: "Bucket",
    shared: false,
    getValue: (r) =>
      rl(r)?.rejection_bucket ? bucketLabel(rl(r).rejection_bucket) : null,
  },
];

// ─── Inline cell renderer ─────────────────────────────────────────────────────
const CellValue = defineComponent({
  props: { col: String, rejection: Object },
  setup(p) {
    return () => {
      const colDef = ALL_COLUMNS.find((c) => c.key === p.col);
      if (!colDef) return h("span", { class: "text-muted-foreground" }, "—");
      if (colDef.render) return colDef.render(p.rejection);
      const val = colDef.getValue(p.rejection);
      return val
        ? h("span", {}, val)
        : h("span", { class: "text-muted-foreground" }, "—");
    };
  },
});

// ─── Visible columns ──────────────────────────────────────────────────────────
const visibleColumns = computed(() => {
  const rows = sortedRejections.value;
  if (!rows.length) return ALL_COLUMNS.filter((c) => c.shared);
  return ALL_COLUMNS.filter((col) => {
    if (col.shared) return true;
    return rows.some((r) => {
      const val = col.getValue(r);
      return val !== null && val !== "" && val !== "—";
    });
  });
});

const totalColspan = computed(() => {
  let n = visibleColumns.value.length;
  if (props.isSuperAdmin) n += 1;
  if (permissionNames.value.includes("acceptance.delete")) n += 1;
  if (
    permissionNames.value.includes("acceptance.update") ||
    permissionNames.value.includes("acceptance.delete")
  )
    n += 1;
  return n;
});

// ─── Sorting ──────────────────────────────────────────────────────────────────
const sortedRejections = computed(() => {
  const rows = [...(props.rejections.data ?? [])];
  return rows.sort((a, b) => {
    const colDef = ALL_COLUMNS.find((c) => c.key === sortColumn.value);
    const valA = colDef ? colDef.getValue(a) ?? "" : "";
    const valB = colDef ? colDef.getValue(b) ?? "" : "";
    if (valA === "—" || valA === "") return 1;
    if (valB === "—" || valB === "") return -1;
    const cmp =
      String(valA).toLowerCase() < String(valB).toLowerCase()
        ? -1
        : String(valA).toLowerCase() > String(valB).toLowerCase()
        ? 1
        : 0;
    return sortDirection.value === "asc" ? cmp : -cmp;
  });
});

function sortBy(key) {
  if (sortColumn.value === key) {
    sortDirection.value = sortDirection.value === "asc" ? "desc" : "asc";
  } else {
    sortColumn.value = key;
    sortDirection.value = "asc";
  }
}

// ─── Normalize rejection for RejectionForm ────────────────────────────────────
const normalizedRejection = computed(() => {
  if (!selectedRejection.value) return null;
  const r = selectedRejection.value;
  const type = getRejectionType(r);
  const base = {
    id: r.id,
    tenant_id: r.tenant_id,
    date: r.date,
    disputed: r.disputed ?? "none",
    carrier_controllable: r.carrier_controllable,
    driver_controllable: r.driver_controllable,
    rejection_reason: r.rejection_reason ?? "",
    penalty: r.penalty,
    type,
  };

  if (type === "advanced_block") {
    const s = ab(r);
    return {
      ...base,
      advance_block_rejection_id: s?.advance_block_rejection_id ?? "",
      week_start: s?.week_start?.split(" ")[0] ?? "",
      week_end: s?.week_end?.split(" ")[0] ?? "",
      impacted_blocks: s?.impacted_blocks ?? "",
      expected_blocks: s?.expected_blocks ?? "",
    };
  }

  if (type === "block") {
    const s = rb(r);
    return {
      ...base,
      block_id: s?.block_id ?? "",
      block_driver_name: s?.driver_name ?? "",
      block_start: s?.block_start ?? "",
      block_end: s?.block_end ?? "",
      rejection_datetime: s?.rejection_datetime ?? "",
    };
  }

  if (type === "load") {
    const s = rl(r);
    return {
      ...base,
      load_id: s?.load_id ?? "",
      load_driver_name: s?.driver_name ?? "",
      origin_yard_arrival: s?.origin_yard_arrival ?? "",
      load_rejection_bucket: s?.rejection_bucket ?? "",
    };
  }

  return base;
});

// ─── Filters ──────────────────────────────────────────────────────────────────
const hasActiveFilters = computed(
  () =>
    !!localFilters.value.search ||
    !!localFilters.value.rejectionType ||
    !!localFilters.value.rejectionBucket ||
    !!localFilters.value.disputed ||
    !!localFilters.value.carrierControllable ||
    !!localFilters.value.driverControllable ||
    !!localFilters.value.penaltyMin ||
    !!localFilters.value.penaltyMax ||
    localFilters.value.rejectionReasonFilter !== "with_reason"
);

// Bucket options are contextual based on selected rejection type
const bucketOptions = computed(() => {
  const type = localFilters.value.rejectionType;
  const loadBuckets = [
    { value: "rejected_after_start_time", label: "After Start Time" },
    { value: "rejected_0_6_hours_before_start_time", label: "0–6 Hours Before" },
    { value: "rejected_6_plus_hours_before_start_time", label: "6+ Hours Before" },
  ];
  const blockBuckets = [
    { value: "less_than_24", label: "Less Than 24 Hours Before" },
    { value: "more_than_24", label: "24+ Hours Before" },
  ];
  if (type === "load") return loadBuckets;
  if (type === "block") return blockBuckets;
  // 'all' or 'advanced_block' — show all applicable buckets grouped
  return [...loadBuckets, ...blockBuckets];
});

// Clear bucket when type changes to avoid stale value
watch(
  () => localFilters.value.rejectionType,
  () => {
    localFilters.value.rejectionBucket = "";
  }
);

function setReasonFilter(value) {
  localFilters.value.rejectionReasonFilter = value;
  getIndexRoute();
}

function getIndexRoute(extra = {}) {
  const routeName = props.tenantSlug ? "acceptance.index" : "acceptance.index.admin";
  const routeParams = props.tenantSlug ? { tenantSlug: props.tenantSlug } : {};
  return router.get(
    route(routeName, routeParams),
    {
      ...localFilters.value,
      perPage: localPerPage.value,
      dateFilter: activeTab.value,
      ...extra,
    },
    { preserveState: true, preserveScroll: true }
  );
}

function applyFilters() {
  getIndexRoute();
}
function selectDateFilter(filter) {
  activeTab.value = filter;
  getIndexRoute({ dateFilter: filter });
}
function changePerPage() {
  getIndexRoute();
}

function resetFilters() {
  localFilters.value = {
    search: "",
    rejectionType: "",
    rejectionBucket: "",
    disputed: "",
    carrierControllable: "",
    driverControllable: "",
    penaltyMin: "",
    penaltyMax: "",
    rejectionReasonFilter: "with_reason",
  };
  getIndexRoute();
}

function visitPage(url) {
  if (!url) return;
  const u = new URL(url);
  getIndexRoute({ page: u.searchParams.get("page") || 1 });
}

// ─── Week number text ─────────────────────────────────────────────────────────
const weekNumberText = computed(() => {
  const { year, weekNumber, startWeekNumber, endWeekNumber } = props;

  // Yesterday / current-week
  if (["yesterday", "current-week"].includes(activeTab.value) && weekNumber && year) {
    return `Week ${weekNumber}, ${year}`;
  }

  // 6w / quarterly
  if (
    ["6w", "quarterly"].includes(activeTab.value) &&
    startWeekNumber &&
    endWeekNumber &&
    year
  ) {
    // ✅ Cross-year case
    if (startWeekNumber > endWeekNumber) {
      return `Weeks ${startWeekNumber}–${endWeekNumber} (${year}–${year + 1})`;
    }

    // Normal same-year case
    return `Weeks ${startWeekNumber}–${endWeekNumber}, ${year}`;
  }

  return "";
});
// ─── Permissions ──────────────────────────────────────────────────────────────
const permissionNames = computed(() => props.permissions.map((p) => p.name));

// ─── Form modal ───────────────────────────────────────────────────────────────
function openForm(rejection = null) {
  selectedRejection.value = rejection;
  formModal.value = true;
}
function onFormSuccess() {
  formModal.value = false;
  successMessage.value = selectedRejection.value
    ? "Rejection updated successfully."
    : "Rejection created successfully.";
}

// ─── Delete single ────────────────────────────────────────────────────────────
function confirmDeleteRejection(id) {
  rejectionToDelete.value = id;
  showDeleteModal.value = true;
}
function deleteRejection(id) {
  const f = useForm({});
  const routeName = props.isSuperAdmin
    ? "acceptance.destroy.admin"
    : "acceptance.destroy";
  const params = props.isSuperAdmin
    ? { rejection: id }
    : { tenantSlug: props.tenantSlug, rejection: id };
  f.delete(route(routeName, params), {
    preserveScroll: true,
    onSuccess: () => {
      successMessage.value = "Rejection deleted successfully.";
      showDeleteModal.value = false;
    },
  });
}

// ─── Delete bulk ──────────────────────────────────────────────────────────────
const isAllSelected = computed(
  () =>
    sortedRejections.value.length > 0 &&
    selectedRejections.value.length === sortedRejections.value.length
);
function toggleSelectAll(e) {
  selectedRejections.value = e.target.checked
    ? sortedRejections.value.map((r) => r.id)
    : [];
}
function confirmDeleteSelected() {
  if (selectedRejections.value.length > 0) showDeleteSelectedModal.value = true;
}
function deleteSelectedRejections() {
  const f = useForm({ ids: selectedRejections.value });
  const routeName = props.isSuperAdmin
    ? "acceptance.destroyBulk.admin"
    : "acceptance.destroyBulk";
  const params = props.isSuperAdmin ? {} : { tenantSlug: props.tenantSlug };
  f.delete(route(routeName, params), {
    preserveScroll: true,
    onSuccess: () => {
      successMessage.value = `${selectedRejections.value.length} records deleted.`;
      selectedRejections.value = [];
      showDeleteSelectedModal.value = false;
    },
  });
}

// ─── Import callbacks ─────────────────────────────────────────────────────────
function onImportSuccess(message) {
  successMessage.value = message;
}
function onImportError(message) {
  errorMessage.value = message;
}

// ─── Export ───────────────────────────────────────────────────────────────────
const exportUrl = computed(() =>
  props.tenantSlug
    ? route("acceptance.export", { tenantSlug: props.tenantSlug })
    : route("acceptance.export.admin")
);
function exportCSV() {
  if (!sortedRejections.value.length) {
    errorMessage.value = "No data to export.";
    return;
  }
  if (exportForm.value) exportForm.value.submit();
}

// ─── Dashboard computed ───────────────────────────────────────────────────────
const acceptanceMetrics = computed(() => {
  const d = props.rejection_breakdown?.by_category;
  if (!d) return null;
  return {
    totalRejections: d.total_rejections ?? 0,
    afterStartCount: d.after_start_count ?? 0,
    moreThan24Count: d.more_than_24_count ?? 0,
    within24Count: d.within_24_count ?? 0,
    advancedRejectionCount: d.advanced_rejection_count ?? 0,
    moreThan6Count: d.more_than_6_count ?? 0,
    within6Count: d.within_6_count ?? 0,
  };
});

const bottomDrivers = computed(() => {
  const t = localFilters.value.rejectionType;
  if (t === "load") return props.rejection_breakdown?.bottom_five_drivers?.load ?? [];
  if (t === "block") return props.rejection_breakdown?.bottom_five_drivers?.block ?? [];
  return props.rejection_breakdown?.bottom_five_drivers?.total ?? [];
});

const acceptanceChartData = computed(() => {
  if (!props.line_chart_data?.length) return { labels: [], datasets: [] };
  return {
    labels: props.line_chart_data.map((i) => i.date),
    datasets: [
      {
        label: "Acceptance Performance",
        data: props.line_chart_data.map((i) => i.acceptancePerformance),
        borderColor: "#3b82f6",
        backgroundColor: "rgba(59,130,246,0.1)",
        tension: 0.3,
      },
    ],
  };
});

// ─── Auto-clear messages ──────────────────────────────────────────────────────
watch(successMessage, (v) => {
  if (v)
    setTimeout(() => {
      successMessage.value = "";
    }, 5000);
});
watch(errorMessage, (v) => {
  if (v)
    setTimeout(() => {
      errorMessage.value = "";
    }, 5000);
});

// ─── Drag-over prevention ─────────────────────────────────────────────────────
onMounted(() => {
  const preventDefault = (e) => e.preventDefault();
  window.addEventListener("dragover", preventDefault);
  window.addEventListener("drop", preventDefault);
  onUnmounted(() => {
    window.removeEventListener("dragover", preventDefault);
    window.removeEventListener("drop", preventDefault);
  });
});
</script>

<style scoped>
.select-base {
  @apply flex h-10 w-full items-center rounded-md border border-input bg-background
         px-3 py-2 text-sm ring-offset-background
         focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2
         disabled:cursor-not-allowed disabled:opacity-50;
}
</style>
