import { useEffect, useRef } from 'react';
import * as echarts from 'echarts';

/**
 * 通用 ECharts 容器
 */
export default function EChart({ option, height = 320 }) {
  const domRef = useRef(null);
  const chartRef = useRef(null);

  useEffect(() => {
    chartRef.current = echarts.init(domRef.current);
    const handleResize = () => chartRef.current?.resize();
    window.addEventListener('resize', handleResize);
    return () => {
      window.removeEventListener('resize', handleResize);
      chartRef.current?.dispose();
      chartRef.current = null;
    };
  }, []);

  useEffect(() => {
    chartRef.current?.setOption(option, true);
  }, [option]);

  return <div ref={domRef} style={{ width: '100%', height }} />;
}
