<script lang="ts">
  import ArrowDownOutlined from '@ant-design/icons-svg/es/asn/ArrowDownOutlined';
  import ArrowLeftOutlined from '@ant-design/icons-svg/es/asn/ArrowLeftOutlined';
  import ArrowRightOutlined from '@ant-design/icons-svg/es/asn/ArrowRightOutlined';
  import ArrowUpOutlined from '@ant-design/icons-svg/es/asn/ArrowUpOutlined';
  import BarChartOutlined from '@ant-design/icons-svg/es/asn/BarChartOutlined';
  import BellOutlined from '@ant-design/icons-svg/es/asn/BellOutlined';
  import CameraOutlined from '@ant-design/icons-svg/es/asn/CameraOutlined';
  import CheckCircleFilled from '@ant-design/icons-svg/es/asn/CheckCircleFilled';
  import CloseCircleFilled from '@ant-design/icons-svg/es/asn/CloseCircleFilled';
  import CloseOutlined from '@ant-design/icons-svg/es/asn/CloseOutlined';
  import DatabaseOutlined from '@ant-design/icons-svg/es/asn/DatabaseOutlined';
  import DeleteOutlined from '@ant-design/icons-svg/es/asn/DeleteOutlined';
  import DownloadOutlined from '@ant-design/icons-svg/es/asn/DownloadOutlined';
  import EditOutlined from '@ant-design/icons-svg/es/asn/EditOutlined';
  import FileTextOutlined from '@ant-design/icons-svg/es/asn/FileTextOutlined';
  import GroupOutlined from '@ant-design/icons-svg/es/asn/GroupOutlined';
  import ImportOutlined from '@ant-design/icons-svg/es/asn/ImportOutlined';
  import InfoCircleOutlined from '@ant-design/icons-svg/es/asn/InfoCircleOutlined';
  import MessageOutlined from '@ant-design/icons-svg/es/asn/MessageOutlined';
  import PieChartOutlined from '@ant-design/icons-svg/es/asn/PieChartOutlined';
  import PictureOutlined from '@ant-design/icons-svg/es/asn/PictureOutlined';
  import PlusOutlined from '@ant-design/icons-svg/es/asn/PlusOutlined';
  import QuestionCircleOutlined from '@ant-design/icons-svg/es/asn/QuestionCircleOutlined';
  import SafetyCertificateOutlined from '@ant-design/icons-svg/es/asn/SafetyCertificateOutlined';
  import SettingOutlined from '@ant-design/icons-svg/es/asn/SettingOutlined';
  import ShopOutlined from '@ant-design/icons-svg/es/asn/ShopOutlined';
  import ShoppingCartOutlined from '@ant-design/icons-svg/es/asn/ShoppingCartOutlined';
  import SortAscendingOutlined from '@ant-design/icons-svg/es/asn/SortAscendingOutlined';
  import SwapOutlined from '@ant-design/icons-svg/es/asn/SwapOutlined';
  import TableOutlined from '@ant-design/icons-svg/es/asn/TableOutlined';
  import TeamOutlined from '@ant-design/icons-svg/es/asn/TeamOutlined';
  import type { IconDefinition } from '@ant-design/icons-svg/es/types';
  import { renderIconDefinitionToSVGElement } from '@ant-design/icons-svg/es/helpers';

  interface Props {
    name: string;
    size?: number;
  }

  let { name, size = 20 }: Props = $props();

  const icons: Record<string, IconDefinition> = {
    admin: SafetyCertificateOutlined,
    'arrow-down': ArrowDownOutlined,
    'arrow-left': ArrowLeftOutlined,
    'arrow-right': ArrowRightOutlined,
    'arrow-up': ArrowUpOutlined,
    bell: BellOutlined,
    camera: CameraOutlined,
    cart: ShoppingCartOutlined,
    chat: MessageOutlined,
    'check-circle': CheckCircleFilled,
    clients: TeamOutlined,
    close: CloseOutlined,
    'close-circle': CloseCircleFilled,
    edit: EditOutlined,
    export: DownloadOutlined,
    group: GroupOutlined,
    help: QuestionCircleOutlined,
    import: ImportOutlined,
    info: InfoCircleOutlined,
    'my-reports': PieChartOutlined,
    picture: PictureOutlined,
    plus: PlusOutlined,
    reports: BarChartOutlined,
    requests: FileTextOutlined,
    settings: SettingOutlined,
    sort: SortAscendingOutlined,
    stocks: DatabaseOutlined,
    substitutions: SwapOutlined,
    trash: DeleteOutlined,
    view: TableOutlined,
    warehouse: ShopOutlined
  };

  const svg = $derived(
    renderIconDefinitionToSVGElement(icons[name] ?? QuestionCircleOutlined, {
      extraSVGAttrs: {
        width: String(size),
        height: String(size),
        fill: 'currentColor',
        'aria-hidden': 'true'
      }
    })
  );
</script>

{@html svg}
